<?php
declare(strict_types=1);

namespace CTF\Server\Repositories;

use CTF\Server\Database\Connection;
use PDOException;

/**
 * Writes + reads `solves`. Solves are unique on (student_id, challenge_id)
 * — DB enforces it; we also catch the duplicate-key exception in the
 * service layer so two parallel submissions don't both award points.
 */
final class SolveRepository
{
    /**
     * Attempt to insert a solve. Returns the new id on success, or null
     * if a solve already exists for this (student_id, challenge_id) pair.
     */
    public function tryCreate(int $studentId, int $challengeId, int $taskSessionId, int $points): ?int
    {
        try {
            Connection::run(
                'INSERT INTO solves
                    (student_id, challenge_id, task_session_id, points)
                 VALUES
                    (:s, :c, :t, :p)',
                [
                    ':s' => $studentId,
                    ':c' => $challengeId,
                    ':t' => $taskSessionId,
                    ':p' => $points,
                ]
            );
            return (int)Connection::pdo()->lastInsertId();
        } catch (PDOException $e) {
            // Duplicate key on (student_id, challenge_id) — already solved.
            if (str_contains($e->getMessage(), 'Duplicate') || $e->getCode() === '23000') {
                return null;
            }
            throw $e;
        }
    }

    /** @return array<string,mixed>|null */
    public function findByStudentChallenge(int $studentId, int $challengeId): ?array
    {
        return Connection::fetchOne(
            'SELECT * FROM solves WHERE student_id = :s AND challenge_id = :c',
            [':s' => $studentId, ':c' => $challengeId]
        );
    }

    /**
     * Total points for a student.
     */
    public function totalPointsForStudent(int $studentId): int
    {
        $row = Connection::fetchOne(
            'SELECT COALESCE(SUM(points), 0) AS p FROM solves WHERE student_id = :s',
            [':s' => $studentId]
        );
        return (int)($row['p'] ?? 0);
    }

    /**
     * Solve count for a student.
     */
    public function countForStudent(int $studentId): int
    {
        $row = Connection::fetchOne(
            'SELECT COUNT(*) AS c FROM solves WHERE student_id = :s',
            [':s' => $studentId]
        );
        return (int)($row['c'] ?? 0);
    }

    /**
     * Active task sessions (not yet completed/cancelled/expired) for a student.
     */
    public function activeTaskCountForStudent(int $studentId): int
    {
        $row = Connection::fetchOne(
            "SELECT COUNT(*) AS c FROM task_sessions
             WHERE student_id = :s AND status = 'active' AND expires_at > NOW()",
            [':s' => $studentId]
        );
        return (int)($row['c'] ?? 0);
    }
}
