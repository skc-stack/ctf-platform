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
}