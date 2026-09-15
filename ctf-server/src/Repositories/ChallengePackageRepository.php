<?php
declare(strict_types=1);

namespace CTF\Server\Repositories;

use CTF\Server\Database\Connection;

/**
 * CRUD for `challenge_packages` table.
 *
 * Each challenge can have multiple versions (packages). Each package
 * is a ZIP file with its own manifest.json and SHA-256 hash.
 */
final class ChallengePackageRepository
{
    /**
     * @param array<string,mixed> $fields Required: challenge_id, version, file_path, sha256, manifest_json.
     *                                     Optional: original_name, file_size.
     * @return int New package id.
     */
    public function create(array $fields): int
    {
        foreach (['challenge_id', 'version', 'file_path', 'sha256', 'manifest_json'] as $k) {
            if (empty($fields[$k])) {
                throw new \InvalidArgumentException("Missing field: {$k}");
            }
        }
        Connection::run(
            'INSERT INTO challenge_packages
                (challenge_id, version, file_path, original_name, file_size, sha256, manifest_json)
             VALUES
                (:c, :v, :p, :n, :s, :h, :m)',
            [
                ':c' => (int)$fields['challenge_id'],
                ':v' => (int)$fields['version'],
                ':p' => (string)$fields['file_path'],
                ':n' => $fields['original_name'] ?? null,
                ':s' => $fields['file_size'] ?? null,
                ':h' => (string)$fields['sha256'],
                ':m' => (string)$fields['manifest_json'],
            ]
        );
        return (int)Connection::pdo()->lastInsertId();
    }

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array
    {
        return Connection::fetchOne('SELECT * FROM challenge_packages WHERE id = :id', [':id' => $id]);
    }

    /** @return array<string,mixed>|null */
    public function findByChallengeVersion(int $challengeId, int $version): ?array
    {
        return Connection::fetchOne(
            'SELECT * FROM challenge_packages WHERE challenge_id = :c AND version = :v',
            [':c' => $challengeId, ':v' => $version]
        );
    }

    /** @return array<string,mixed>|null */
    public function findLatestByChallenge(int $challengeId): ?array
    {
        return Connection::fetchOne(
            'SELECT * FROM challenge_packages
             WHERE challenge_id = :c
             ORDER BY version DESC
             LIMIT 1',
            [':c' => $challengeId]
        );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function listByChallenge(int $challengeId): array
    {
        return Connection::fetchAll(
            'SELECT * FROM challenge_packages
             WHERE challenge_id = :c
             ORDER BY version DESC',
            [':c' => $challengeId]
        );
    }

    public function delete(int $id): bool
    {
        $affected = Connection::run(
            'DELETE FROM challenge_packages WHERE id = :id',
            [':id' => $id]
        )->rowCount();
        return $affected > 0;
    }
}