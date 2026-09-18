<?php
declare(strict_types=1);

namespace CTF\Server\Repositories;

use CTF\Server\Database\Connection;

/**
 * Repository for `devices` table.
 *
 * Devices belong to a user (student) and store a hash of the device token.
 * The token itself is only shown once upon activation.
 */
final class DeviceRepository
{
    public const STATUS_ACTIVE   = 'active';
    public const STATUS_REVOKED  = 'revoked';
    public const STATUS_DISABLED = 'disabled';

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array
    {
        return Connection::fetchOne('SELECT * FROM devices WHERE id = :id', [':id' => $id]);
    }

    /** @return array<string,mixed>|null */
    public function findByUuid(string $uuid): ?array
    {
        return Connection::fetchOne('SELECT * FROM devices WHERE uuid = :u', [':u' => $uuid]);
    }

    /** @return array<string,mixed>|null */
    public function findByTokenHash(string $tokenHash): ?array
    {
        return Connection::fetchOne('SELECT * FROM devices WHERE device_token_hash = :h', [':h' => $tokenHash]);
    }

    /**
     * @param array<string,mixed> $fields Required: uuid, user_id, name, device_token_hash.
     *                                     Optional: status, agent_version, target_version, last_ip.
     * @return int New device id.
     */
    public function create(array $fields): int
    {
        foreach (['uuid', 'user_id', 'name', 'device_token_hash'] as $k) {
            if (empty($fields[$k])) {
                throw new \InvalidArgumentException("Missing field: {$k}");
            }
        }
        Connection::run(
            'INSERT INTO devices
                (uuid, user_id, name, device_token_hash, status, agent_version, target_version, last_ip)
             VALUES
                (:u, :user, :name, :hash, :status, :agent, :target, :ip)',
            [
                ':u'    => (string)$fields['uuid'],
                ':user' => (int)$fields['user_id'],
                ':name' => (string)$fields['name'],
                ':hash' => (string)$fields['device_token_hash'],
                ':status' => $fields['status'] ?? self::STATUS_ACTIVE,
                ':agent' => $fields['agent_version'] ?? null,
                ':target' => $fields['target_version'] ?? null,
                ':ip'   => $fields['last_ip'] ?? null,
            ]
        );
        return (int)Connection::pdo()->lastInsertId();
    }

    public function updateStatus(int $id, string $status): bool
    {
        $affected = Connection::run(
            'UPDATE devices SET status = :s WHERE id = :id',
            [':s' => $status, ':id' => $id]
        )->rowCount();
        return $affected > 0;
    }

    public function touchLastSeen(int $id, string $ip = null, string $agentVersion = null, string $targetVersion = null): bool
    {
        $set = [];
        $params = [':id' => $id];
        if ($ip !== null) {
            $set[] = 'last_ip = :ip';
            $params[':ip'] = $ip;
        }
        if ($agentVersion !== null) {
            $set[] = 'agent_version = :agent';
            $params[':agent'] = $agentVersion;
        }
        if ($targetVersion !== null) {
            $set[] = 'target_version = :target';
            $params[':target'] = $targetVersion;
        }
        $set[] = 'last_seen_at = NOW()';

        if (empty($set)) {
            // only update timestamp
            $set = ['last_seen_at = NOW()'];
        }

        $sql = 'UPDATE devices SET ' . implode(', ', $set) . ' WHERE id = :id';
        $affected = Connection::run($sql, $params)->rowCount();
        return $affected > 0;
    }

    /**
     * Revoke a device (set status to revoked).
     */
    public function revoke(int $id): bool
    {
        return $this->updateStatus($id, self::STATUS_REVOKED);
    }

    /**
     * Get all active devices for a user.
     * @return array<int,array<string,mixed>>
     */
    public function listActiveByUser(int $userId): array
    {
        return Connection::fetchAll(
            'SELECT * FROM devices WHERE user_id = :u AND status = :s ORDER BY last_seen_at DESC',
            [':u' => $userId, ':s' => self::STATUS_ACTIVE]
        );
    }

    /**
     * List all devices with user info, paginated.
     * @param int $page 1-based page number
     * @param int $perPage items per page
     * @return array{rows: array<int,array<string,mixed>>, total: int, page: int, perPage: int}
     */
    public function listAll(int $page = 1, int $perPage = 20): array
    {
        $offset = max(0, ($page - 1) * $perPage);
        $total = (int)Connection::fetchOne(
            'SELECT COUNT(*) AS c FROM devices'
        )['c'];

        $rows = Connection::fetchAll(
            'SELECT d.*,
                    u.username AS user_username,
                    u.display_name AS user_display_name,
                    u.role AS user_role,
                    ts.uuid AS task_uuid,
                    ts.started_at AS task_started_at,
                    ts.expires_at AS task_expires_at,
                    ts.status AS task_status,
                    c.title AS challenge_title,
                    c.slug AS challenge_slug,
                    (SELECT COUNT(*) FROM device_sync_status WHERE device_id = d.id) AS synced_challenges_count,
                    (SELECT MAX(synced_at) FROM device_sync_status WHERE device_id = d.id) AS last_synced_at
             FROM devices d
             JOIN users u ON u.id = d.user_id
             LEFT JOIN task_sessions ts ON ts.device_id = d.id AND ts.status = "active"
             LEFT JOIN challenges c ON c.id = ts.challenge_id
             ORDER BY d.last_seen_at DESC
             LIMIT :limit OFFSET :offset',
            [
                ':limit'  => $perPage,
                ':offset' => $offset,
            ]
        );

        return [
            'rows'    => $rows,
            'total'   => $total,
            'page'    => $page,
            'perPage' => $perPage,
        ];
    }

    /**
     * Get synced challenges for a device.
     * @return array<int,array<string,mixed>>
     */
    public function getSyncedChallenges(int $deviceId): array
    {
        return Connection::fetchAll(
            'SELECT ds.*, c.title AS challenge_title, c.slug AS challenge_slug
             FROM device_sync_status ds
             LEFT JOIN challenges c ON c.challenge_id = ds.challenge_id
             WHERE ds.device_id = :did
             ORDER BY ds.synced_at DESC',
            [':did' => $deviceId]
        );
    }

    /**
     * List all activation codes with user info, including used/expired status.
     * @return array<int,array<string,mixed>>
     */
    public function listActivationCodes(): array
    {
        return Connection::fetchAll(
            'SELECT dac.*, u.username AS user_username, u.display_name AS user_display_name
             FROM device_activation_codes dac
             JOIN users u ON u.id = dac.user_id
             ORDER BY dac.created_at DESC'
        );
    }

    /**
     * Delete an activation code by id.
     */
    public function deleteActivationCode(int $id): bool
    {
        $affected = Connection::run(
            'DELETE FROM device_activation_codes WHERE id = :id',
            [':id' => $id]
        )->rowCount();
        return $affected > 0;
    }
}