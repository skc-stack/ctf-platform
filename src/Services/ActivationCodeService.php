<?php
declare(strict_types=1);

namespace CTF\Server\Services;

use CTF\Server\Database\Connection;
use CTF\Server\Repositories\UserRepository;

/**
 * Activation code handling: generate, store, verify, and mark used.
 *
 * Codes are ACT-XXXX-XXXX-XXXX where each X is from base32 alphabet
 * (excluding I/O/0/1/L to avoid visual confusion). Same alphabet as CAPTCHA
 * and group join_code.
 *
 * Codes are single-use and expire after 10 minutes (configurable).
 */
final class ActivationCodeService
{
    /** base32 alphabet (no I / O / 0 / 1 / L) */
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    private const CODE_LENGTH = 4;
    private const GROUPS = 3;
    private const PREFIX = 'ACT-';

    public function __construct(
        private readonly UserRepository $users = new UserRepository(),
    ) {}

    /**
     * Generate a new activation code for a user.
     *
     * @param int $userId The user ID (must be student, but we don't enforce here)
     * @param int $ttlMinutes Time to live in minutes (default from config)
     * @return string The plain-text activation code (only shown once)
     */
    public function generate(int $userId, int $ttlMinutes = null): string
    {
        // Validate user exists and is student? We'll let caller decide.
        $user = $this->users->findById($userId);
        if (!$user) {
            throw new \InvalidArgumentException('User not found');
        }

        // Determine TTL
        if ($ttlMinutes === null) {
            $ttlMinutes = (int)\CTF\Server\Support\Config::get('ACTIVATION_CODE_TTL', 10);
        }
        $expiresAt = (new \DateTimeImmutable())->modify("+{$ttlMinutes} minutes")->format('Y-m-d H:i:s');

        // Generate code until we find an unused hash (extremely unlikely to collide)
        do {
            $code = $this->generateCode();
            $codeHash = hash('sha256', $code);
            $existing = Connection::fetchOne(
                'SELECT id FROM device_activation_codes WHERE code_hash = :h',
                [':h' => $codeHash]
            );
        } while ($existing !== null);

        // Store the hash
        Connection::run(
            'INSERT INTO device_activation_codes
                (user_id, code_hash, expires_at, created_at)
             VALUES
                (:u, :h, :e, NOW())',
            [
                ':u' => $userId,
                ':h' => $codeHash,
                ':e' => $expiresAt,
            ]
        );

        return $code;
    }

    /**
     * Verify an activation code and mark it as used if valid.
     *
     * @param string $code The plain-text activation code
     * @return array|null User row if valid and not expired/used, else null
     */
    public function verifyAndUse(string $code): ?array
    {
        if ($code === '' || !preg_match('/^ACT-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/', $code)) {
            return null;
        }

        $codeHash = hash('sha256', $code);
        $row = Connection::fetchOne(
            'SELECT dac.*, u.id AS user_id, u.username, u.role
             FROM device_activation_codes dac
             JOIN users u ON u.id = dac.user_id
             WHERE dac.code_hash = :h
               AND dac.used_at IS NULL
               AND dac.expires_at > NOW()',
            [':h' => $codeHash]
        );

        if ($row === null) {
            return null;
        }

        // Mark as used
        Connection::run(
            'UPDATE device_activation_codes
             SET used_at = NOW()
             WHERE id = :id',
            [':id' => (int)$row['id']]
        );

        // Return user data (we need the whole user row for device creation)
        $user = $this->users->findById((int)$row['user_id']);
        return $user ?: null;
    }

    /**
     * Clean up expired activation codes (called by CLI).
     *
     * @return int Number of rows deleted
     */
    public function cleanupExpired(): int
    {
        $result = Connection::run(
            'DELETE FROM device_activation_codes WHERE expires_at < NOW()'
        );
        return $result->rowCount();
    }

    /**
     * Generate a random activation code string.
     */
    private function generateCode(): string
    {
        $segments = [];
        for ($g = 0; $g < self::GROUPS; $g++) {
            $segment = '';
            for ($i = 0; $i < self::CODE_LENGTH; $i++) {
                $segment .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
            $segments[] = $segment;
        }
        return self::PREFIX . implode('-', $segments);
    }
}