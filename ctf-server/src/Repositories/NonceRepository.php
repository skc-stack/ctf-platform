<?php
declare(strict_types=1);

namespace CTF\Server\Repositories;

use CTF\Server\Database\Connection;
use PDOException;

/**
 * One-time nonces for device-side task complete requests.
 *
 * Target must send a fresh nonce per complete call so the Server can reject
 * replays. The Server stores only the hash; the nonce + timestamp come
 * from the device's local clock.
 */
final class NonceRepository
{
    /**
     * Try to consume a nonce. Returns true if the nonce was fresh (so the
     * request is allowed), false if it was already used.
     *
     * Expired nonces (older than 10 min) are considered stale — they're
     * deleted so the table doesn't grow forever.
     */
    public function consume(int $deviceId, string $nonce, int $ttlSec = 600): bool
    {
        $nonceHash = hash('sha256', $nonce);
        // Opportunistic cleanup.
        Connection::run(
            'DELETE FROM nonces WHERE expires_at < NOW()'
        );
        try {
            Connection::run(
                'INSERT INTO nonces
                    (device_id, nonce_hash, expires_at)
                 VALUES
                    (:d, :h, NOW() + INTERVAL :ttl SECOND)',
                [':d' => $deviceId, ':h' => $nonceHash, ':ttl' => $ttlSec]
            );
            return true;
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), 'Duplicate') || $e->getCode() === '23000') {
                return false;
            }
            throw $e;
        }
    }
}
