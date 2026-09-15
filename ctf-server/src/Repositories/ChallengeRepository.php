<?php
declare(strict_types=1);

namespace CTF\Server\Repositories;

use CTF\Server\Database\Connection;

/**
 * CRUD + lookups for `challenges` and `challenge_groups`.
 *
 * Challenge visibility:
 *   - challenge_groups = (empty) → public (all active students can see)
 *   - challenge_groups = (groups) → only students in those groups can see
 *
 * `listForStudent($studentId)` returns published challenges the student
 * is allowed to see, joined with solve count.
 */
final class ChallengeRepository
{
    public const STATUS_DRAFT     = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_DISABLED  = 'disabled';

    public const VERIFICATION_FLAG       = 'flag';
    public const VERIFICATION_AUTOMATIC  = 'automatic';

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array
    {
        return Connection::fetchOne('SELECT * FROM challenges WHERE id = :id', [':id' => $id]);
    }

    /** @return array<string,mixed>|null */
    public function findByUuid(string $uuid): ?array
    {
        return Connection::fetchOne('SELECT * FROM challenges WHERE uuid = :u', [':u' => $uuid]);
    }

    /** @return array<string,mixed>|null */
    public function findBySlug(string $slug): ?array
    {
        return Connection::fetchOne('SELECT * FROM challenges WHERE slug = :s', [':s' => $slug]);
    }

    /**
     * List challenges a teacher has created (any status).
     * @return array<int,array<string,mixed>>
     */
    public function listByTeacher(int $teacherId): array
    {
        return Connection::fetchAll(
            'SELECT c.*, COUNT(cg.group_id) AS group_count
             FROM challenges c
             LEFT JOIN challenge_groups cg ON cg.challenge_id = c.id
             WHERE c.teacher_id = :t
             GROUP BY c.id
             ORDER BY c.created_at DESC, c.id DESC',
            [':t' => $teacherId]
        );
    }

    /**
     * List challenges visible to a student:
     *   - status = published
     *   - AND (no group bindings, OR the student is in at least one of them)
     * @return array<int,array<string,mixed>>
     */
    public function listForStudent(int $studentId): array
    {
        return Connection::fetchAll(
            'SELECT c.*,
                    (SELECT COUNT(*) FROM solves s WHERE s.challenge_id = c.id) AS solve_count,
                    EXISTS(SELECT 1 FROM solves s2 WHERE s2.challenge_id = c.id AND s2.student_id = :sid) AS solved_by_me
             FROM challenges c
             WHERE c.status = :pub
               AND (
                 NOT EXISTS(SELECT 1 FROM challenge_groups cg WHERE cg.challenge_id = c.id)
                 OR EXISTS(
                    SELECT 1 FROM challenge_groups cg2
                    JOIN group_members gm ON gm.group_id = cg2.group_id
                    WHERE cg2.challenge_id = c.id
                      AND gm.student_id = :sid2
                      AND gm.status = :active
                 )
               )
             ORDER BY c.published_at DESC, c.id DESC',
            [':pub' => self::STATUS_PUBLISHED, ':sid' => $studentId, ':sid2' => $studentId, ':active' => 'active']
        );
    }

    /**
     * List the group_ids a challenge is bound to.
     * Empty result means "public to everyone".
     * @return array<int,int>
     */
    public function listGroupIdsForChallenge(int $challengeId): array
    {
        $rows = Connection::fetchAll(
            'SELECT group_id FROM challenge_groups WHERE challenge_id = :c',
            [':c' => $challengeId]
        );
        return array_map(static fn($r) => (int)$r['group_id'], $rows);
    }

    /**
     * Bind a challenge to N groups (replaces any prior bindings).
     */
    public function setGroupBindings(int $challengeId, array $groupIds): void
    {
        Connection::transaction(function () use ($challengeId, $groupIds) {
            Connection::run('DELETE FROM challenge_groups WHERE challenge_id = :c', [':c' => $challengeId]);
            foreach ($groupIds as $gid) {
                Connection::run(
                    'INSERT INTO challenge_groups (challenge_id, group_id) VALUES (:c, :g)',
                    [':c' => $challengeId, ':g' => (int)$gid]
                );
            }
        });
    }

    public function publish(int $id): bool
    {
        $affected = Connection::run(
            'UPDATE challenges SET status = :s, published_at = NOW() WHERE id = :id',
            [':s' => self::STATUS_PUBLISHED, ':id' => $id]
        )->rowCount();
        return $affected > 0;
    }

    public function disable(int $id): bool
    {
        $affected = Connection::run(
            'UPDATE challenges SET status = :s WHERE id = :id',
            [':s' => self::STATUS_DISABLED, ':id' => $id]
        )->rowCount();
        return $affected > 0;
    }

    /**
     * Create a new challenge (status=draft).
     * @param array<string,mixed> $fields Required: teacher_id, title, slug, category, difficulty.
     *                                     Optional: description, points, version.
     * @return int New challenge id.
     */
    public function create(array $fields): int
    {
        foreach (['teacher_id', 'title', 'slug', 'category', 'difficulty'] as $k) {
            if (empty($fields[$k])) {
                throw new \InvalidArgumentException("Missing field: {$k}");
            }
        }
        // Generate UUID v4
        $uuid = sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            random_int(0, 0xffff), random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff)
        );
        Connection::run(
            'INSERT INTO challenges
                (uuid, teacher_id, title, slug, category, difficulty, description, points, version, status)
             VALUES
                (:u, :t, :title, :slug, :cat, :diff, :desc, :points, :ver, :st)',
            [
                ':u'     => $uuid,
                ':t'     => (int)$fields['teacher_id'],
                ':title' => (string)$fields['title'],
                ':slug'  => (string)$fields['slug'],
                ':cat'   => (string)$fields['category'],
                ':diff'  => (string)$fields['difficulty'],
                ':desc'  => (string)($fields['description'] ?? ''),
                ':points'=> (int)($fields['points'] ?? 100),
                ':ver'   => (int)($fields['version'] ?? 1),
                ':st'    => self::STATUS_DRAFT,
            ]
        );
        return (int)Connection::pdo()->lastInsertId();
    }

    /**
     * Update challenge metadata (NOT slug, uuid, teacher_id, or created_at).
     * @param array<string,mixed> $fields Optional: title, description, category, difficulty, points, version.
     * @return bool True if updated.
     */
    public function update(int $id, array $fields): bool
    {
        $allowed = ['title', 'description', 'category', 'difficulty', 'points', 'version'];
        $updates = [];
        $params = [':id' => $id];
        foreach ($allowed as $k) {
            if (array_key_exists($k, $fields)) {
                $updates[] = "{$k} = :{$k}";
                $params[":{$k}"] = $fields[$k];
            }
        }
        if (empty($updates)) {
            return false;
        }
        $affected = Connection::run(
            'UPDATE challenges SET ' . implode(', ', $updates) . ' WHERE id = :id',
            $params
        )->rowCount();
        return $affected > 0;
    }

    public function delete(int $id): bool
    {
        $affected = Connection::run(
            'DELETE FROM challenges WHERE id = :id',
            [':id' => $id]
        )->rowCount();
        return $affected > 0;
    }
}
