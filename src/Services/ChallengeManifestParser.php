<?php
declare(strict_types=1);

namespace CTF\Server\Services;

use CTF\Server\Database\Connection;
use CTF\Server\Repositories\ChallengeRepository;

/**
 * Parses + validates a challenge manifest.json, then persists it to
 * the Server's DB (without going through the upload UI — that's §2.4).
 *
 * Used by `bin/seed-challenge.php` to publish a challenge directly from a
 * built ZIP. The full upload UI would call this same Service internally
 * once we build it; this CLI just bypasses the HTTP layer for the MVP.
 *
 * Schema validation mirrors the Server's authoritative rules:
 *   - schema_version = 1
 *   - required fields: challenge_id, version, type, difficulty, title, entrypoint, verification
 *   - verification.type = flag requires flag_static
 *   - verification.type = automatic requires automatic.script
 *
 * Zip Slip protection: we never extract here — we only read manifest.json
 * from the ZIP, which is a single named entry. The actual extraction
 * happens on the Target VM by the Python Agent.
 */
final class ChallengeManifestParser
{
    public const ALLOWED_TYPES = ['web', 'crypto', 'reverse', 'pwn', 'forensic', 'misc', 'network'];
    public const ALLOWED_DIFFICULTIES = ['easy', 'medium', 'hard', 'expert'];

    /**
     * Validate the manifest and return the normalized array. Throws on
     * any schema error.
     *
     * @return array<string,mixed>
     */
    public static function validate(array $data): array
    {
        if (!is_array($data)) {
            throw new \InvalidArgumentException('manifest must be a JSON object');
        }
        $schemaVersion = $data['schema_version'] ?? null;
        if ($schemaVersion !== 1) {
            throw new \InvalidArgumentException("unsupported schema_version: " . var_export($schemaVersion, true));
        }
        foreach (['challenge_id', 'version', 'type', 'difficulty', 'title', 'entrypoint', 'verification'] as $req) {
            if (!isset($data[$req])) {
                throw new \InvalidArgumentException("missing required field: {$req}");
            }
        }
        if (!in_array($data['type'], self::ALLOWED_TYPES, true)) {
            throw new \InvalidArgumentException("invalid type: {$data['type']}");
        }
        if (!in_array($data['difficulty'], self::ALLOWED_DIFFICULTIES, true)) {
            throw new \InvalidArgumentException("invalid difficulty: {$data['difficulty']}");
        }
        if (!preg_match('/^[A-Za-z0-9_-]{1,190}$/', $data['challenge_id'])) {
            throw new \InvalidArgumentException("challenge_id must match [A-Za-z0-9_-]{1,190}");
        }
        if ((int)$data['version'] < 1) {
            throw new \InvalidArgumentException("version must be >= 1");
        }
        $ver = $data['verification'];
        if (!is_array($ver)) {
            throw new \InvalidArgumentException("verification must be an object");
        }
        $vtype = $ver['type'] ?? null;
        if (!in_array($vtype, ['flag', 'automatic'], true)) {
            throw new \InvalidArgumentException("invalid verification.type");
        }
        if ($vtype === 'flag' && empty($ver['flag_static'])) {
            throw new \InvalidArgumentException("flag verification requires flag_static");
        }
        return $data;
    }

    /**
     * Insert the challenge into the DB. Returns the new challenge id.
     */
    public static function insert(string $teacherUsername, array $manifest, string $zipPath, string $zipSha256): int
    {
        // Find teacher.
        $teacher = Connection::fetchOne(
            'SELECT id FROM users WHERE username = :u',
            [':u' => $teacherUsername]
        );
        if ($teacher === null) {
            throw new \InvalidArgumentException("teacher not found: {$teacherUsername}");
        }
        $teacherId = (int)$teacher['id'];

        // Generate UUID.
        $uuid = sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            random_int(0, 0xffff), random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff),
        );

        $vtype = $manifest['verification']['type'];
        $chId = Connection::transaction(function () use ($teacherId, $uuid, $manifest, $vtype, $zipPath, $zipSha256) {
            Connection::run(
                'INSERT INTO challenges
                    (uuid, teacher_id, title, slug, category, difficulty, description,
                     points, version, status, verification_type, published_at)
                 VALUES
                    (:u, :t, :title, :slug, :cat, :diff, :desc,
                     :points, :ver, :st, :vt, NOW())',
                [
                    ':u'     => $uuid,
                    ':t'     => $teacherId,
                    ':title' => (string)$manifest['title'],
                    ':slug'  => (string)$manifest['challenge_id'],
                    ':cat'   => (string)$manifest['type'],
                    ':diff'  => (string)$manifest['difficulty'],
                    ':desc'  => (string)($manifest['description'] ?? ''),
                    ':points'=> (int)($manifest['points'] ?? 100),
                    ':ver'   => (int)$manifest['version'],
                    ':st'    => ChallengeRepository::STATUS_PUBLISHED,
                    ':vt'    => $vtype,
                ]
            );
            $newId = (int)Connection::pdo()->lastInsertId();
            Connection::run(
                'INSERT INTO challenge_packages
                    (challenge_id, version, file_path, original_name, file_size, sha256, manifest_json)
                 VALUES
                    (:c, :v, :p, :n, :s, :h, :m)',
                [
                    ':c' => $newId,
                    ':v' => (int)$manifest['version'],
                    ':p' => $zipPath,
                    ':n' => basename($zipPath),
                    ':s' => filesize($zipPath) ?: 0,
                    ':h' => $zipSha256,
                    ':m' => json_encode($manifest, JSON_UNESCAPED_UNICODE),
                ]
            );
            return $newId;
        });

        return $chId;
    }
}
