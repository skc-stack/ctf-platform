<?php
declare(strict_types=1);

namespace CTF\Server\Services;

use CTF\Server\Database\Connection;
use CTF\Server\Repositories\UserRepository;
use CTF\Server\Support\Config;

/**
 * Email verification token lifecycle.
 *
 * Issue:   issueToken($userId) → raw token (64-char hex) to embed in URL
 * Verify:  verify($token) → user row on success, null on failure
 *
 * On verify:
 *   - Sets users.email_verified_at = NOW()
 *   - If user.role == student and user.status != 'active', promotes to active
 *   - If user.role == teacher, leaves status = 'pending' (admin still approves)
 *   - Marks token.used_at = NOW() (one-shot)
 */
final class VerificationService
{
    public function __construct(
        private readonly UserRepository $users = new UserRepository(),
    ) {}

    public function issueToken(int $userId): string
    {
        $token = bin2hex(random_bytes(32));
        $hash  = hash('sha256', $token);
        $ttlMin = (int)Config::get('EMAIL_VERIFICATION_TTL', 1440);
        $expires = (new \DateTimeImmutable())
            ->modify('+' . $ttlMin . ' minutes')
            ->format('Y-m-d H:i:s');

        Connection::run(
            'INSERT INTO email_verification_tokens (user_id, token_hash, expires_at)
             VALUES (:u, :h, :e)',
            [':u' => $userId, ':h' => $hash, ':e' => $expires]
        );
        return $token;
    }

    /**
     * @return array|null updated user row on success, null if token invalid/expired/used
     */
    public function verify(string $token): ?array
    {
        if ($token === '' || strlen($token) < 32) {
            return null;
        }
        $hash = hash('sha256', $token);

        $row = Connection::fetchOne(
            'SELECT * FROM email_verification_tokens
             WHERE token_hash = :h AND used_at IS NULL AND expires_at > NOW()
             LIMIT 1',
            [':h' => $hash]
        );
        if (!$row) return null;

        $user = $this->users->findById((int)$row['user_id']);
        if (!$user) return null;

        Connection::transaction(function () use ($row, $user) {
            Connection::run(
                'UPDATE email_verification_tokens SET used_at = NOW() WHERE id = :id',
                [':id' => $row['id']]
            );
            Connection::run(
                'UPDATE users SET email_verified_at = NOW() WHERE id = :id',
                [':id' => $user['id']]
            );
            // Student auto-activates; teacher remains pending until admin approval.
            if ($user['role'] === UserRepository::ROLE_STUDENT
                && $user['status'] !== UserRepository::STATUS_ACTIVE) {
                Connection::run(
                    'UPDATE users SET status = :s WHERE id = :id',
                    [':s' => UserRepository::STATUS_ACTIVE, ':id' => $user['id']]
                );
            }
        });

        return $this->users->findById((int)$user['id']);
    }
}
