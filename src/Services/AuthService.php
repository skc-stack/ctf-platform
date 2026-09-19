<?php
declare(strict_types=1);

namespace CTF\Server\Services;

use CTF\Server\Database\Connection;
use CTF\Server\Http\Request;
use CTF\Server\Repositories\UserRepository;
use CTF\Server\Security\CSRF;
use CTF\Server\Security\PasswordHasher;

/**
 * Business logic for authentication, registration, session lifecycle.
 *
 * Controllers call into this; this layer enforces status checks,
 * session_regenerate_id(true), CSRF rotation, and audit logging.
 */
final class AuthService
{
    public function __construct(
        private readonly UserRepository $users = new UserRepository(),
    ) {}

    /**
     * Verify username + password. Returns user row on success, null on failure.
     * Constant-time password_verify is provided by password_hash().
     *
     * Login is rejected (returns null) if:
     *   - username not found
     *   - password does not match
     *   - status != 'active' (pending / disabled)
     */
    public function attemptLogin(string $username, string $password): ?array
    {
        $user = $this->users->findByUsername($username);
        if (!$user) {
            // Run a dummy verify to keep timing roughly constant
            PasswordHasher::verify($password, '$argon2id$v=19$m=65536,t=4,p=1$ZHVtbXk$ZHVtbXk');
            return null;
        }
        if (!PasswordHasher::verify($password, (string)$user['password_hash'])) {
            return null;
        }
        if ($user['status'] !== UserRepository::STATUS_ACTIVE) {
            return null;
        }
        return $user;
    }

    /**
     * Start a session for $user. Rotates session id and CSRF token.
     * Writes audit_log entry.
     */
    public function login(array $user, Request $req): void
    {
        // Regenerate session id to prevent session fixation
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        // Strip sensitive fields before storing in session. Record login time
        // and IP for the layout's session info footer.
        $_SESSION['user'] = [
            'id' => (int)$user['id'],
            'username' => (string)$user['username'],
            'display_name' => (string)$user['display_name'],
            'role' => (string)$user['role'],
            'status' => (string)$user['status'],
            'login_at' => time(),
            'login_ip' => (string)$req->ip(),
        ];
        CSRF::rotate();

        $this->users->updateLastLogin((int)$user['id']);

        AuditLog::fromRequest($req, 'login', 'user', (string)$user['id']);
    }

    public function logout(Request $req): void
    {
        $userId = $_SESSION['user']['id'] ?? null;
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                [
                    'expires' => time() - 42000,
                    'path' => $params['path'],
                    'domain' => $params['domain'],
                    'secure' => $params['secure'],
                    'httponly' => $params['httponly'],
                    'samesite' => $params['samesite'] ?? 'Lax',
                ]
            );
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }

        if ($userId !== null) {
            AuditLog::log('logout', (int)$userId);
        }
    }

    /**
     * Register a student. Returns user id, or throws on validation failure.
     *
     * Email is required for students (used for verification + password reset).
     * Student ↔ teacher linkage is via groups (separate model), not via
     * institutional fields like student_number / class_name / school_name.
     */
    public function registerStudent(array $input, Request $req): int
    {
        $username = trim((string)($input['username'] ?? ''));
        $email = trim((string)($input['email'] ?? ''));
        $password = (string)($input['password'] ?? '');
        $passwordConfirm = (string)($input['password_confirm'] ?? '');
        $displayName = trim((string)($input['display_name'] ?? ''));

        $err = $this->validateCommonFields($username, $email, $password, $passwordConfirm, $displayName, requireEmail: true);
        if ($err !== null) {
            throw new \InvalidArgumentException($err);
        }

        return Connection::transaction(function () use ($username, $email, $password, $displayName, $req) {
            $id = $this->users->create([
                'username' => $username,
                'email' => $email !== '' ? $email : null,
                'password' => $password,
                'display_name' => $displayName,
                'role' => UserRepository::ROLE_STUDENT,
                'status' => UserRepository::STATUS_PENDING,
            ]);
            AuditLog::log('register_student', $id, 'user', (string)$id, [
                'username' => $username,
            ], $req->ip(), $req->userAgent());
            return $id;
        });
    }

    /**
     * Register a teacher. Status defaults to PENDING; requires admin approval.
     */
    public function registerTeacher(array $input, Request $req): int
    {
        $username = trim((string)($input['username'] ?? ''));
        $email = trim((string)($input['email'] ?? ''));
        $password = (string)($input['password'] ?? '');
        $passwordConfirm = (string)($input['password_confirm'] ?? '');
        $displayName = trim((string)($input['display_name'] ?? ''));

        $err = $this->validateCommonFields($username, $email, $password, $passwordConfirm, $displayName);
        if ($err !== null) {
            throw new \InvalidArgumentException($err);
        }

        return Connection::transaction(function () use ($username, $email, $password, $displayName, $req) {
            $id = $this->users->create([
                'username' => $username,
                'email' => $email !== '' ? $email : null,
                'password' => $password,
                'display_name' => $displayName,
                'role' => UserRepository::ROLE_TEACHER,
                'status' => UserRepository::STATUS_PENDING,
            ]);
            AuditLog::log('register_teacher', $id, 'user', (string)$id, [
                'username' => $username,
                'status' => UserRepository::STATUS_PENDING,
            ], $req->ip(), $req->userAgent());
            return $id;
        });
    }

    /**
     * Approve / reject a pending teacher. Admin-only; called from AdminController.
     */
    public function approveUser(int $userId, string $newStatus, Request $req): bool
    {
        if (!in_array($newStatus, [UserRepository::STATUS_ACTIVE, UserRepository::STATUS_DISABLED], true)) {
            throw new \InvalidArgumentException('Invalid target status');
        }
        $ok = $this->users->updateStatus($userId, $newStatus);
        if ($ok) {
            $action = $newStatus === UserRepository::STATUS_ACTIVE ? 'teacher_approved' : 'user_disabled';
            AuditLog::fromRequest($req, $action, 'user', (string)$userId, ['new_status' => $newStatus]);
        }
        return $ok;
    }

    private function validateCommonFields(
        string $username,
        string $email,
        string $password,
        string $passwordConfirm,
        string $displayName,
        bool $requireEmail = false,
    ): ?string {
        if ($username === '' || strlen($username) < 3 || strlen($username) > 64) {
            return '帳號長度需介於 3-64 字元';
        }
        if (!preg_match('/^[A-Za-z0-9_.-]+$/', $username)) {
            return '帳號只能含英數字、底線、句點與連字號';
        }
        if ($requireEmail && $email === '') {
            return 'Email 為必填';
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return 'Email 格式不正確';
        }
        if ($displayName === '' || strlen($displayName) > 100) {
            return '顯示名稱不可為空且不可超過 100 字元';
        }
        $pwErr = PasswordHasher::policyError($password);
        if ($pwErr !== null) {
            return $pwErr;
        }
        if ($password !== $passwordConfirm) {
            return '兩次密碼輸入不一致';
        }
        return null;
    }
}
