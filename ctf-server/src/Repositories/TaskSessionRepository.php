<?php
declare(strict_types=1);

namespace CTF\Server\Repositories;

use CTF\Server\Database\Connection;

/**
 * CRUD for `task_sessions`.
 *
 * Tokens are stored as SHA-256 hashes — the plain token is only shown to
 * the student once at start time.
 */
final class TaskSessionRepository
{
    public const STATUS_ACTIVE    = 'active';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_EXPIRED   = 'expired';
    public const STATUS_CANCELLED = 'cancelled';

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array
    {
        return Connection::fetchOne('SELECT * FROM task_sessions WHERE id = :id', [':id' => $id]);
    }

    /** @return array<string,mixed>|null */
    public function findByUuid(string $uuid): ?array
    {
        return Connection::fetchOne('SELECT * FROM task_sessions WHERE uuid = :u', [':u' => $uuid]);
    }

    /** @return array<string,mixed>|null */
    public function findByTokenHash(string $tokenHash): ?array
    {
        return Connection::fetchOne(
            'SELECT * FROM task_sessions WHERE token_hash = :h',
            [':h' => $tokenHash]
        );
    }

    /**
     * Create a new task session.
     * @param array<string,mixed> $fields Required: uuid, student_id, challenge_id, token_hash, expires_at.
     * @return int New id.
     */
    public function create(array $fields): int
    {
        foreach (['uuid', 'student_id', 'challenge_id', 'token_hash', 'expires_at'] as $k) {
            if (empty($fields[$k])) {
                throw new \InvalidArgumentException("Missing field: {$k}");
            }
        }
        Connection::run(
            'INSERT INTO task_sessions
                (uuid, student_id, challenge_id, token_hash, status, expires_at)
             VALUES
                (:u, :s, :c, :h, :st, :e)',
            [
                ':u'  => (string)$fields['uuid'],
                ':s'  => (int)$fields['student_id'],
                ':c'  => (int)$fields['challenge_id'],
                ':h'  => (string)$fields['token_hash'],
                ':st' => self::STATUS_ACTIVE,
                ':e'  => (string)$fields['expires_at'],
            ]
        );
        return (int)Connection::pdo()->lastInsertId();
    }

    /**
     * Bind a device to the task. Idempotent: if already bound to the same
     * device, no-op. If bound to a different device, returns false so the
     * caller can reject the request.
     */
    public function bindDevice(int $taskId, int $deviceId): bool
    {
        $row = $this->findById($taskId);
        if ($row === null) {
            return false;
        }
        if ($row['device_id'] !== null) {
            return (int)$row['device_id'] === $deviceId;
        }
        Connection::run(
            'UPDATE task_sessions SET device_id = :d WHERE id = :id',
            [':d' => $deviceId, ':id' => $taskId]
        );
        return true;
    }

    public function markCompleted(int $taskId): bool
    {
        $affected = Connection::run(
            'UPDATE task_sessions
             SET status = :s, completed_at = NOW()
             WHERE id = :id AND status = :active',
            [':s' => self::STATUS_COMPLETED, ':id' => $taskId, ':active' => self::STATUS_ACTIVE]
        )->rowCount();
        return $affected > 0;
    }

    public function markCancelled(int $taskId): bool
    {
        $affected = Connection::run(
            'UPDATE task_sessions
             SET status = :s, completed_at = NOW()
             WHERE id = :id AND status = :active',
            [':s' => self::STATUS_CANCELLED, ':id' => $taskId, ':active' => self::STATUS_ACTIVE]
        )->rowCount();
        return $affected > 0;
    }

    /**
     * Mark all active+expired (past expires_at) tasks as expired.
     * Returns affected row count.
     */
    public function expireOverdue(): int
    {
        return Connection::run(
            "UPDATE task_sessions
             SET status = 'expired'
             WHERE status = 'active' AND expires_at < NOW()"
        )->rowCount();
    }

    /**
     * Active tasks for a student (status=active, expires_at > NOW).
     * @return array<int,array<string,mixed>>
     */
    public function listActiveByStudent(int $studentId): array
    {
        return Connection::fetchAll(
            "SELECT t.*, c.title AS challenge_title, c.slug AS challenge_slug
             FROM task_sessions t
             JOIN challenges c ON c.id = t.challenge_id
             WHERE t.student_id = :s
               AND t.status = 'active'
               AND t.expires_at > NOW()
             ORDER BY t.started_at DESC",
            [':s' => $studentId]
        );
    }
}
