<?php
declare(strict_types=1);

namespace CTF\Server\Services;

use CTF\Server\Database\Connection;
use CTF\Server\Repositories\UserRepository;
use CTF\Server\Security\PasswordHasher;
use CTF\Server\Support\Config;

/**
 * Password reset token lifecycle.
 *
 * Lookup by username OR email (caller may decide). Returns raw token (64-char
 * hex) to embed in URL, or null if user not found / no email on file.
 *
 * The caller MUST NOT distinguish "user not found" from "user found, email
 * sent" to the requester — both cases should yield the same generic message
 * (to avoid account enumeration).
 */
final class PasswordResetService
{
    public function __construct(
        private readonly UserRepository $users = new UserRepository(),
    ) {}

    /**
     * Returns the raw token to embed in the reset URL. Returns null if:
     *   - no user with that identifier
     *   - user has no email on file (cannot send reset link)
     *   - user has not verified their email yet (cannot send)
     *
     * Throws no exceptions; caller handles the absence.
     */
    public function requestReset(string $identifier): ?string
    {
        if ($identifier === '') return null;

        $user = $this->users->findByUsername($identifier)
              ?? $this->users->findByEmail($identifier);

        if (!$user) return null;
        if (empty($user['email'])) return null;
        if (($user['status'] ?? '') === UserRepository::STATUS_DISABLED) return null;

        $token = bin2hex(random_bytes(32));
        $hash  = hash('sha256', $token);
        $ttlMin = (int)Config::get('PASSWORD_RESET_TTL', 60);
        $expires = (new \DateTimeImmutable())
            ->modify('+' . $ttlMin . ' minutes')
            ->format('Y-m-d H:i:s');

        Connection::run(
            'INSERT INTO password_reset_tokens (user_id, token_hash, expires_at)
             VALUES (:u, :h, :e)',
            [':u' => $user['id'], ':h' => $hash, ':e' => $expires]
        );
        return $token;
    }

    /**
     * Whether a token is currently valid (unused, unexpired).
     * Used by GET form to decide whether to show the password fields.
     */
    public function isValid(string $token): bool
    {
        if ($token === '' || strlen($token) < 32) return false;
        $hash = hash('sha256', $token);
        $row = Connection::fetchOne(
            'SELECT id FROM password_reset_tokens
             WHERE token_hash = :h AND used_at IS NULL AND expires_at > NOW()
             LIMIT 1',
            [':h' => $hash]
        );
        return $row !== null;
    }

    /**
     * Reset the password. Returns the user id on success, null if token is
     * invalid. Throws InvalidArgumentException on policy violation (weak pwd).
     */
    public function reset(string $token, string $newPassword): ?int
    {
        if ($token === '' || strlen($token) < 32) return null;

        $pwErr = PasswordHasher::policyError($newPassword);
        if ($pwErr !== null) {
            throw new \InvalidArgumentException($pwErr);
        }

        $hash = hash('sha256', $token);
        $row = Connection::fetchOne(
            'SELECT * FROM password_reset_tokens
             WHERE token_hash = :h AND used_at IS NULL AND expires_at > NOW()
             LIMIT 1',
            [':h' => $hash]
        );
        if (!$row) return null;

        $userId = (int)$row['user_id'];

        Connection::transaction(function () use ($row, $userId, $newPassword) {
            Connection::run(
                'UPDATE password_reset_tokens SET used_at = NOW() WHERE id = :id',
                [':id' => $row['id']]
            );
            Connection::run(
                'UPDATE users SET password_hash = :h WHERE id = :id',
                [':h' => PasswordHasher::hash($newPassword), ':id' => $userId]
            );
        });

        return $userId;
    }
}
