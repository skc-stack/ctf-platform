<?php
declare(strict_types=1);

namespace CTF\Server\Repositories;

use CTF\Server\Database\Connection;

/**
 * CRUD for `group_members` table.
 *
 * `status=left/banned` are soft states — we never hard-delete rows so
 * the audit trail survives. addOrReactivate() handles rejoin-after-leave.
 */
final class GroupMemberRepository
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_LEFT   = 'left';
    public const STATUS_BANNED = 'banned';

    /** @return array<string,mixed>|null */
    public function find(int $groupId, int $studentId): ?array
    {
        return Connection::fetchOne(
            'SELECT * FROM group_members
             WHERE group_id = :g AND student_id = :s',
            [':g' => $groupId, ':s' => $studentId]
        );
    }

    /**
     * Add a student to a group. If a prior row exists (left/banned),
     * reactivate it instead of inserting a duplicate.
     *
     * @return bool true if newly inserted, false if reactivated.
     */
    public function addOrReactivate(int $groupId, int $studentId): bool
    {
        $existing = $this->find($groupId, $studentId);
        if ($existing !== null) {
            Connection::run(
                'UPDATE group_members
                    SET status = :st, joined_at = NOW(), left_at = NULL
                  WHERE id = :id',
                [':st' => self::STATUS_ACTIVE, ':id' => (int)$existing['id']]
            );
            return false;
        }
        Connection::run(
            'INSERT INTO group_members
                (group_id, student_id, status, joined_at)
             VALUES
                (:g, :s, :st, NOW())',
            [':g' => $groupId, ':s' => $studentId, ':st' => self::STATUS_ACTIVE]
        );
        return true;
    }

    /**
     * Mark student as `left`. No-op if already left/banned.
     */
    public function markLeft(int $groupId, int $studentId): bool
    {
        $existing = $this->find($groupId, $studentId);
        if (!$existing || $existing['status'] !== self::STATUS_ACTIVE) {
            return false;
        }
        Connection::run(
            'UPDATE group_members
                SET status = :st, left_at = NOW()
              WHERE id = :id',
            [':st' => self::STATUS_LEFT, ':id' => (int)$existing['id']]
        );
        return true;
    }

    /**
     * Mark student as `banned`. Banned students cannot rejoin the same group.
     */
    public function markBanned(int $groupId, int $studentId): bool
    {
        $existing = $this->find($groupId, $studentId);
        if (!$existing || $existing['status'] !== self::STATUS_ACTIVE) {
            return false;
        }
        Connection::run(
            'UPDATE group_members
                SET status = :st, left_at = NOW()
              WHERE id = :id',
            [':st' => self::STATUS_BANNED, ':id' => (int)$existing['id']]
        );
        return true;
    }

    /**
     * List active members of a group with their user data (for teacher view).
     * @return array<int,array<string,mixed>>
     */
    public function listActiveMembers(int $groupId): array
    {
        return Connection::fetchAll(
            'SELECT gm.id, gm.group_id, gm.student_id, gm.status,
                    gm.joined_at, gm.left_at,
                    u.username, u.display_name, u.email
             FROM group_members gm
             JOIN users u ON u.id = gm.student_id
             WHERE gm.group_id = :g AND gm.status = :st
             ORDER BY gm.joined_at ASC',
            [':g' => $groupId, ':st' => self::STATUS_ACTIVE]
        );
    }

    /**
     * List groups the student is currently an active member of.
     * @return array<int,array<string,mixed>>
     */
    public function listActiveByStudent(int $studentId): array
    {
        return Connection::fetchAll(
            'SELECT g.*, gm.joined_at, t.display_name AS teacher_name,
                    COUNT(gm2.id) AS member_count
             FROM group_members gm
             JOIN `groups` g ON g.id = gm.group_id
             JOIN users t ON t.id = g.teacher_id
             LEFT JOIN group_members gm2
                    ON gm2.group_id = g.id AND gm2.status = :active
             WHERE gm.student_id = :s AND gm.status = :st
             GROUP BY g.id, gm.joined_at, t.display_name
             ORDER BY gm.joined_at DESC',
            [':s' => $studentId, ':st' => self::STATUS_ACTIVE, ':active' => self::STATUS_ACTIVE]
        );
    }
}
