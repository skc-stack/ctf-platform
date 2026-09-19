<?php
declare(strict_types=1);

namespace CTF\Server\Services;

use CTF\Server\Database\Connection;
use CTF\Server\Http\Request;
use CTF\Server\Repositories\GroupMemberRepository;
use CTF\Server\Repositories\GroupRepository;
use CTF\Server\Repositories\UserRepository;

/**
 * High-level business logic for groups: create / join / leave / remove / regenerate-code.
 *
 * Why a Service:
 *  - Generates unique join_code (with retry on collision)
 *  - Enforces teacher/student role checks before mutating
 *  - Writes audit_log rows for every state change
 *  - Wraps writes in transactions
 */
final class GroupService
{
    /** base32 alphabet (no I / O / 0 / 1 / L) — same as CAPTCHA so visual confusion is avoided. */
    private const JOIN_CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    private const JOIN_CODE_LENGTH   = 8;
    private const JOIN_CODE_MAX_RETRIES = 5;

    public function __construct(
        private readonly GroupRepository $groups = new GroupRepository(),
        private readonly GroupMemberRepository $members = new GroupMemberRepository(),
        private readonly UserRepository $users = new UserRepository(),
    ) {}

    /**
     * Generate a unique 8-char join_code. Retries up to JOIN_CODE_MAX_RETRIES times on collision.
     */
    public function generateUniqueJoinCode(): string
    {
        $alphabet = self::JOIN_CODE_ALPHABET;
        $len = strlen($alphabet);
        for ($i = 0; $i < self::JOIN_CODE_MAX_RETRIES; $i++) {
            $code = '';
            for ($j = 0; $j < self::JOIN_CODE_LENGTH; $j++) {
                $code .= $alphabet[random_int(0, $len - 1)];
            }
            if ($this->groups->findByJoinCode($code) === null) {
                return $code;
            }
        }
        throw new \RuntimeException('Failed to generate a unique join_code after ' . self::JOIN_CODE_MAX_RETRIES . ' retries');
    }

    /**
     * Teacher creates a new group.
     * @return array<string,mixed> The created group row.
     */
    public function createGroup(
        int $teacherId,
        string $name,
        ?string $description,
        ?int $maxMembers,
        Request $req,
    ): array {
        $name = trim($name);
        if ($name === '' || strlen($name) > 100) {
            throw new \InvalidArgumentException('群組名稱不可為空且不可超過 100 字元');
        }
        $description = $description !== null ? trim($description) : null;
        if ($description === '') {
            $description = null;
        }
        if ($maxMembers !== null && $maxMembers < 1) {
            throw new \InvalidArgumentException('人數上限需 ≥ 1（不限制請留空）');
        }

        $teacher = $this->users->findById($teacherId);
        if (!$teacher || $teacher['role'] !== UserRepository::ROLE_TEACHER) {
            throw new \InvalidArgumentException('只有老師可以建立群組');
        }

        $uuid = sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            random_int(0, 0xffff), random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff),
        );
        $code = $this->generateUniqueJoinCode();

        $id = Connection::transaction(function () use ($teacherId, $name, $description, $maxMembers, $uuid, $code) {
            return $this->groups->create([
                'uuid' => $uuid,
                'teacher_id' => $teacherId,
                'name' => $name,
                'description' => $description,
                'max_members' => $maxMembers,
                'join_code' => $code,
                'status' => GroupRepository::STATUS_ACTIVE,
            ]);
        });

        AuditLog::fromRequest($req, 'group_create', 'group', (string)$id, [
            'name' => $name,
            'join_code' => $code,
            'max_members' => $maxMembers,
        ]);

        return $this->groups->findById($id) ?? [];
    }

    /**
     * Student joins a group by code. Throws on invalid code / archived / banned / full.
     * @return array<string,mixed> The group row.
     */
    public function joinByCode(int $studentId, string $code, Request $req): array
    {
        $code = strtoupper(trim($code));
        if (!preg_match('/^[A-Z0-9]{4,16}$/', $code)) {
            throw new \InvalidArgumentException('邀請碼格式不正確');
        }
        $student = $this->users->findById($studentId);
        if (!$student || $student['role'] !== UserRepository::ROLE_STUDENT) {
            throw new \InvalidArgumentException('只有學生可以加入群組');
        }

        $group = $this->groups->findByJoinCode($code);
        if ($group === null) {
            throw new \InvalidArgumentException('找不到此邀請碼對應的群組');
        }
        if ($group['status'] !== GroupRepository::STATUS_ACTIVE) {
            throw new \InvalidArgumentException('此群組已封存，無法加入');
        }

        $existing = $this->members->find((int)$group['id'], $studentId);
        if ($existing !== null) {
            if ($existing['status'] === GroupMemberRepository::STATUS_BANNED) {
                throw new \InvalidArgumentException('你已被此群組的管理者踢出，無法再次加入');
            }
            if ($existing['status'] === GroupMemberRepository::STATUS_ACTIVE) {
                throw new \InvalidArgumentException('你已經在此群組中');
            }
            // status=left: reactivate allowed
        }

        if ($group['max_members'] !== null) {
            $current = $this->groups->countActiveMembers((int)$group['id']);
            if ($current >= (int)$group['max_members']) {
                throw new \InvalidArgumentException('此群組已達人數上限');
            }
        }

        Connection::transaction(function () use ($group, $studentId) {
            $this->members->addOrReactivate((int)$group['id'], $studentId);
        });

        AuditLog::fromRequest($req, 'group_join', 'group', (string)$group['id'], [
            'student_id' => $studentId,
            'by_student_username' => $student['username'],
        ]);

        return $group;
    }

    /**
     * Student leaves a group. No-op if not active.
     */
    public function leave(int $groupId, int $studentId, Request $req): bool
    {
        $group = $this->groups->findById($groupId);
        if ($group === null) {
            throw new \InvalidArgumentException('找不到此群組');
        }
        $ok = $this->members->markLeft($groupId, $studentId);
        if ($ok) {
            AuditLog::fromRequest($req, 'group_leave', 'group', (string)$groupId, [
                'student_id' => $studentId,
            ]);
        }
        return $ok;
    }

    /**
     * Teacher removes a student from their group.
     */
    public function remove(int $teacherId, int $groupId, int $studentId, Request $req): bool
    {
        $group = $this->groups->findById($groupId);
        if ($group === null) {
            throw new \InvalidArgumentException('找不到此群組');
        }
        if ((int)$group['teacher_id'] !== $teacherId) {
            throw new \InvalidArgumentException('你並非此群組的管理者');
        }
        $ok = $this->members->markBanned($groupId, $studentId);
        if ($ok) {
            AuditLog::fromRequest($req, 'group_remove', 'group', (string)$groupId, [
                'student_id' => $studentId,
                'by_teacher_id' => $teacherId,
            ]);
        }
        return $ok;
    }

    /**
     * Teacher regenerates a group's join_code.
     */
    public function regenerateCode(int $teacherId, int $groupId, Request $req): string
    {
        $group = $this->groups->findById($groupId);
        if ($group === null) {
            throw new \InvalidArgumentException('找不到此群組');
        }
        if ((int)$group['teacher_id'] !== $teacherId) {
            throw new \InvalidArgumentException('你並非此群組的管理者');
        }
        $newCode = $this->generateUniqueJoinCode();
        $this->groups->updateJoinCode($groupId, $newCode);
        AuditLog::fromRequest($req, 'group_regenerate_code', 'group', (string)$groupId);
        return $newCode;
    }

    /**
     * Teacher deletes a group entirely. CASCADE removes members.
     */
    public function deleteGroup(int $teacherId, int $groupId, Request $req): bool
    {
        $group = $this->groups->findById($groupId);
        if ($group === null) {
            return false;
        }
        if ((int)$group['teacher_id'] !== $teacherId) {
            throw new \InvalidArgumentException('你並非此群組的管理者');
        }
        $memberCount = $this->groups->countActiveMembers($groupId);
        $ok = $this->groups->delete($groupId);
        if ($ok) {
            AuditLog::fromRequest($req, 'group_delete', 'group', (string)$groupId, [
                'name' => $group['name'],
                'member_count_at_delete' => $memberCount,
            ]);
        }
        return $ok;
    }
}
