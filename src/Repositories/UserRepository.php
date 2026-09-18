<?php
declare(strict_types=1);

namespace CTF\Server\Repositories;

use CTF\Server\Database\Connection;
use CTF\Server\Security\PasswordHasher;

/**
 * User CRUD + lookups.
 */
final class UserRepository
{
    public const ROLE_ADMIN = 'admin';
    public const ROLE_TEACHER = 'teacher';
    public const ROLE_STUDENT = 'student';

    public const STATUS_PENDING = 'pending';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_DISABLED = 'disabled';

    public function findById(int $id): ?array
    {
        return Connection::fetchOne('SELECT * FROM users WHERE id = :id', [':id' => $id]);
    }

    public function findByUsername(string $username): ?array
    {
        return Connection::fetchOne(
            'SELECT * FROM users WHERE username = :u',
            [':u' => $username]
        );
    }

    public function findByEmail(string $email): ?array
    {
        return Connection::fetchOne(
            'SELECT * FROM users WHERE email = :e',
            [':e' => $email]
        );
    }

    /**
     * Create a new user.
     *
     * @param array<string,mixed> $fields Required: username, password, display_name, role.
     *                                     Optional: email, status.
     * @return int New user id.
     */
    public function create(array $fields): int
    {
        $required = ['username', 'password', 'display_name'];
        foreach ($required as $k) {
            if (empty($fields[$k])) {
                throw new \InvalidArgumentException("Missing field: {$k}");
            }
        }

        $passwordHash = PasswordHasher::hash((string)$fields['password']);

        Connection::run(
            'INSERT INTO users
                (username, email, password_hash, display_name, role, status)
             VALUES
                (:u, :e, :p, :d, :r, :s)',
            [
                ':u'  => (string)$fields['username'],
                ':e'  => $fields['email'] ?? null,
                ':p'  => $passwordHash,
                ':d'  => (string)$fields['display_name'],
                ':r'  => $fields['role'] ?? UserRepository::ROLE_STUDENT,
                ':s'  => $fields['status'] ?? self::STATUS_ACTIVE,
            ]
        );

        return (int)Connection::pdo()->lastInsertId();
    }

    public function updateStatus(int $id, string $status): bool
    {
        $affected = Connection::run(
            'UPDATE users SET status = :s WHERE id = :id',
            [':s' => $status, ':id' => $id]
        )->rowCount();
        return $affected > 0;
    }

    public function updateLastLogin(int $id): void
    {
        Connection::run(
            'UPDATE users SET last_login_at = NOW() WHERE id = :id',
            [':id' => $id]
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function listByRole(string $role): array
    {
        return Connection::fetchAll(
            'SELECT id, username, email, display_name, role, status, last_login_at, created_at
             FROM users
             WHERE role = :r
             ORDER BY id DESC',
            [':r' => $role]
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function listByStatus(string $status): array
    {
        return Connection::fetchAll(
            'SELECT id, username, email, display_name, role, status, last_login_at, created_at
             FROM users
             WHERE status = :s
             ORDER BY id DESC',
            [':s' => $status]
        );
    }

    /** @return array<string,mixed>|null */
    public function statsForStudent(int $id): ?array
    {
        return Connection::fetchOne(
            'SELECT
                u.id,
                u.username,
                u.display_name,
                COALESCE(SUM(s.points), 0) AS total_score,
                COUNT(s.id) AS solved_count,
                MAX(s.solved_at) AS last_solve
             FROM users u
             LEFT JOIN solves s ON s.student_id = u.id
             WHERE u.id = :id AND u.role = :r
             GROUP BY u.id',
            [':id' => $id, ':r' => self::ROLE_STUDENT]
        );
    }

    public function countActiveByRole(string $role): int
    {
        $row = Connection::fetchOne(
            'SELECT COUNT(*) AS c FROM users WHERE role = :r AND status = :s',
            [':r' => $role, ':s' => self::STATUS_ACTIVE]
        );
        return (int)($row['c'] ?? 0);
    }
}
