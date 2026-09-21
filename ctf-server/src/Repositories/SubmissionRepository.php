<?php
declare(strict_types=1);

namespace CTF\Server\Repositories;

use CTF\Server\Database\Connection;

/**
 * Writes to `submissions`. Each attempt (correct or wrong) is recorded.
 */
final class SubmissionRepository
{
    /**
     * @param array<string,mixed> $fields Required: student_id, challenge_id, task_session_id, submitted_flag_hash, correct.
     *                                     Optional: source_ip.
     * @return int New id.
     */
    public function create(array $fields): int
    {
        foreach (['student_id', 'challenge_id', 'task_session_id', 'submitted_flag_hash', 'correct'] as $k) {
            if (!isset($fields[$k])) {
                throw new \InvalidArgumentException("Missing field: {$k}");
            }
        }
        Connection::run(
            'INSERT INTO submissions
                (student_id, challenge_id, task_session_id, submitted_flag_hash, correct, source_ip)
             VALUES
                (:s, :c, :t, :h, :ok, :ip)',
            [
                ':s'  => (int)$fields['student_id'],
                ':c'  => (int)$fields['challenge_id'],
                ':t'  => (int)$fields['task_session_id'],
                ':h'  => (string)$fields['submitted_flag_hash'],
                ':ok' => (int)((bool)$fields['correct']),
                ':ip' => $fields['source_ip'] ?? null,
            ]
        );
        return (int)Connection::pdo()->lastInsertId();
    }

    /**
     * Count correct submissions for a student + challenge (for "already solved" checks).
     */
    public function countCorrect(int $studentId, int $challengeId): int
    {
        $row = Connection::fetchOne(
            'SELECT COUNT(*) AS c FROM submissions
             WHERE student_id = :s AND challenge_id = :c AND correct = 1',
            [':s' => $studentId, ':c' => $challengeId]
        );
        return (int)($row['c'] ?? 0);
    }

    /**
     * Count wrong (incorrect) submissions for a student + challenge.
     * Used to calculate attempt count for scoring decay.
     */
    public function countWrong(int $studentId, int $challengeId): int
    {
        $row = Connection::fetchOne(
            'SELECT COUNT(*) AS c FROM submissions
             WHERE student_id = :s AND challenge_id = :c AND correct = 0',
            [':s' => $studentId, ':c' => $challengeId]
        );
        return (int)($row['c'] ?? 0);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function listByStudent(int $studentId, int $limit = 50): array
    {
        return Connection::fetchAll(
            'SELECT sub.*, c.title AS challenge_title
             FROM submissions sub
             JOIN challenges c ON c.id = sub.challenge_id
             WHERE sub.student_id = :s
             ORDER BY sub.id DESC
             LIMIT ' . max(1, min(500, $limit)),
            [':s' => $studentId]
        );
    }
}
