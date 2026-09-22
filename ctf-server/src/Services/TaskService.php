<?php
declare(strict_types=1);

namespace CTF\Server\Services;

use CTF\Server\Database\Connection;
use CTF\Server\Http\Request;
use CTF\Server\Repositories\ChallengeRepository;
use CTF\Server\Repositories\DeviceRepository;
use CTF\Server\Repositories\TaskSessionRepository;
use CTF\Server\Repositories\UserRepository;
use CTF\Server\Support\Config;

/**
 * Task Session business logic: start, validate, complete.
 *
 * Tokens look like `TASK-XXXX-XXXX-XXXX-XXXX` (base32 alphabet, no I/O/0/1/L).
 * The plain token is returned to the student once at start time; the DB
 * stores SHA-256(token) only.
 *
 * Validation rules (5.4):
 *   - task status = 'active'
 *   - task.expires_at > NOW()
 *   - challenge.status = 'published'
 *   - device.status = 'active'
 *   - task.student_id == device.user_id
 *   - First validate binds device_id; subsequent must match.
 */
final class TaskService
{
    private const TOKEN_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    private const TOKEN_SEGMENTS = 4;
    private const TOKEN_SEG_LEN = 4;
    private const TOKEN_PREFIX = 'TASK-';

    public function __construct(
        private readonly TaskSessionRepository $tasks = new TaskSessionRepository(),
        private readonly ChallengeRepository $challenges = new ChallengeRepository(),
        private readonly DeviceRepository $devices = new DeviceRepository(),
        private readonly UserRepository $users = new UserRepository(),
    ) {}

    /**
     * Student starts a task for a challenge.
     *
     * @return array{task: array<string,mixed>, token: string}
     */
    public function start(int $studentId, int $challengeId, ?int $ttlMinutes = null, ?Request $req = null): array
    {
        $student = $this->users->findById($studentId);
        if (!$student || $student['role'] !== UserRepository::ROLE_STUDENT) {
            throw new \InvalidArgumentException('只有學生可以啟動任務');
        }

        $challenge = $this->challenges->findById($challengeId);
        if ($challenge === null) {
            throw new \InvalidArgumentException('找不到此題目');
        }
        if ($challenge['status'] !== ChallengeRepository::STATUS_PUBLISHED) {
            throw new \InvalidArgumentException('此題目尚未發布或已下架');
        }
        // Visibility: if the challenge is bound to specific groups, the student
        // must be an active member of at least one of them.
        $boundGroupIds = $this->challenges->listGroupIdsForChallenge($challengeId);
        if (!empty($boundGroupIds)) {
            $placeholders = [];
            $bindParams = [':s' => $studentId, ':st' => 'active'];
            foreach ($boundGroupIds as $i => $gid) {
                $key = ":g{$i}";
                $placeholders[] = $key;
                $bindParams[$key] = (int)$gid;
            }
            $row = Connection::fetchOne(
                'SELECT COUNT(*) AS c FROM group_members
                 WHERE student_id = :s AND status = :st AND group_id IN (' . implode(',', $placeholders) . ')',
                $bindParams
            );
            $allowed = (int)($row['c'] ?? 0);
            if ($allowed === 0) {
                throw new \InvalidArgumentException('你不在此題目的允許群組中');
            }
        }

        $ttl = $ttlMinutes ?? (int)Config::get('DEFAULT_TASK_TTL', 120);
        $expiresAt = (new \DateTimeImmutable())
            ->modify("+{$ttl} minutes")
            ->format('Y-m-d H:i:s');

        // Generate token, ensure no collision (extremely unlikely).
        do {
            $token = $this->generateToken();
            $tokenHash = hash('sha256', $token);
            $existing = $this->tasks->findByTokenHash($tokenHash);
        } while ($existing !== null);

        $uuid = sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            random_int(0, 0xffff), random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff),
        );

        $taskId = $this->tasks->create([
            'uuid' => $uuid,
            'student_id' => $studentId,
            'challenge_id' => $challengeId,
            'token_hash' => $tokenHash,
            'expires_at' => $expiresAt,
        ]);

        if ($req !== null) {
            AuditLog::fromRequest($req, 'task_start', 'task', (string)$taskId, [
                'challenge_id' => $challengeId,
                'ttl_minutes' => $ttl,
            ]);
        }

        $task = $this->tasks->findById($taskId) ?? [];
        return ['task' => $task, 'token' => $token];
    }

    /**
     * Device validates a task token. Returns the challenge's entrypoint and version.
     *
     * @param array<string,mixed> $device Authenticated device row from DeviceAuth middleware.
     * @return array{task: array<string,mixed>, challenge: array<string,mixed>}
     * @throws TaskValidationException
     */
    public function validate(string $taskToken, array $device, ?Request $req = null): array
    {
        if ($taskToken === '' || !preg_match('/^TASK-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/', $taskToken)) {
            throw new TaskValidationException('invalid_token', 'Task token 格式不正確');
        }
        $tokenHash = hash('sha256', $taskToken);
        $task = $this->tasks->findByTokenHash($tokenHash);
        if ($task === null) {
            throw new TaskValidationException('unknown_token', 'Task token 不存在');
        }
        if ($task['status'] !== TaskSessionRepository::STATUS_ACTIVE) {
            throw new TaskValidationException('inactive', "Task 狀態為 {$task['status']}，無法驗證");
        }
        if (strtotime((string)$task['expires_at']) < time()) {
            throw new TaskValidationException('expired', 'Task 已過期');
        }

        // Device must be active.
        if ($device['status'] !== DeviceRepository::STATUS_ACTIVE) {
            throw new TaskValidationException('device_inactive', '裝置已被停用或撤銷', 401);
        }

        // First validate binds device; subsequent must match.
        // Note: we do NOT check device.user_id == task.student_id here,
        // allowing multiple students to share the same VM.
        $bindOk = $this->tasks->bindDevice((int)$task['id'], (int)$device['id']);
        if (!$bindOk) {
            throw new TaskValidationException('device_mismatch', '此 Task 已被綁定到其他裝置', 403);
        }

        // Challenge must still be published.
        $challenge = $this->challenges->findById((int)$task['challenge_id']);
        if ($challenge === null || $challenge['status'] !== ChallengeRepository::STATUS_PUBLISHED) {
            throw new TaskValidationException('challenge_unavailable', '題目已下架或刪除', 410);
        }

        if ($req !== null) {
            AuditLog::fromRequest($req, 'task_validate', 'task', (string)$task['id'], [
                'device_id' => (int)$device['id'],
            ]);
        }

        // Generate dynamic flag for all challenge types (flag + automatic).
        // Automatic challenges write this flag to .current_flag for their verifier script.
        $flag = FlagGenerator::compute((int)$task['student_id'], (string)$challenge['uuid'], (string)$task['uuid']);

        return [
            'task' => $task,
            'challenge' => $challenge,
            'flag' => $flag,
        ];
    }

    /**
     * Mark task completed (called from Phase 6 / Submission flow).
     */
    public function complete(int $taskId, ?Request $req = null): bool
    {
        $ok = $this->tasks->markCompleted($taskId);
        if ($ok && $req !== null) {
            AuditLog::fromRequest($req, 'task_complete', 'task', (string)$taskId);
        }
        return $ok;
    }

    public function cancel(int $taskId, int $studentId, ?Request $req = null): bool
    {
        $task = $this->tasks->findById($taskId);
        if (!$task || (int)$task['student_id'] !== $studentId) {
            throw new \InvalidArgumentException('找不到此任務或非本人');
        }
        if ($task['status'] !== TaskSessionRepository::STATUS_ACTIVE) {
            return false;
        }
        $ok = $this->tasks->markCancelled($taskId);
        if ($ok && $req !== null) {
            AuditLog::fromRequest($req, 'task_cancel', 'task', (string)$taskId);
        }
        return $ok;
    }

    private function generateToken(): string
    {
        $segments = [];
        $alphabet = self::TOKEN_ALPHABET;
        $alphaLen = strlen($alphabet);
        for ($g = 0; $g < self::TOKEN_SEGMENTS; $g++) {
            $seg = '';
            for ($i = 0; $i < self::TOKEN_SEG_LEN; $i++) {
                $seg .= $alphabet[random_int(0, $alphaLen - 1)];
            }
            $segments[] = $seg;
        }
        return self::TOKEN_PREFIX . implode('-', $segments);
    }

    /** @return TaskValidationException */
    private function err(string $code, string $message, int $http = 400): TaskValidationException
    {
        return new TaskValidationException($code, $message, $http);
    }
}
