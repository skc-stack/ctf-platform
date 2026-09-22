<?php
declare(strict_types=1);

namespace CTF\Server\Controllers;

use CTF\Server\Http\Request;
use CTF\Server\Http\Response;
use CTF\Server\Repositories\UserRepository;
use CTF\Server\Services\AuthService;
use CTF\Server\Services\CaptchaService;
use CTF\Server\Services\Mailer;
use CTF\Server\Services\PasswordResetService;
use CTF\Server\Services\VerificationService;
use CTF\Server\Support\Config;

final class AuthController extends BaseController
{
    public function __construct(
        private readonly AuthService $auth = new AuthService(),
        private readonly UserRepository $users = new UserRepository(),
        private readonly VerificationService $verification = new VerificationService(),
        private readonly PasswordResetService $passwordReset = new PasswordResetService(),
        private readonly Mailer $mailer = new Mailer(),
        private readonly CaptchaService $captcha = new CaptchaService(),
    ) {}

    /* ===== Register (single entry, role chosen in form) ===== */

    public function showRegister(Request $req): Response
    {
        $role = (string)($_GET['role'] ?? 'student');
        if (!in_array($role, ['student', 'teacher'], true)) {
            $role = 'student';
        }
        return $this->view($req, 'auth/register', [
            'title' => '註冊 — CTF LAB',
            'old' => $this->sessionOld() + ['_role' => $role],
            'role' => $role,
        ]);
    }

    public function register(Request $req): Response
    {
        $role = (string)($req->post['_role'] ?? 'student');
        if (!in_array($role, ['student', 'teacher'], true)) {
            $role = 'student';
        }
        try {
            if ($role === 'teacher') {
                $id = $this->auth->registerTeacher($req->post, $req);
            } else {
                $id = $this->auth->registerStudent($req->post, $req);
            }
            $user = $this->users->findById($id);
            // Send verification email if we have an email address on file.
            if (!empty($user['email'])) {
                $token = $this->verification->issueToken($id);
                $verifyUrl = $this->absoluteUrl('/verify-email') . '?token=' . $token;
                $sent = $this->mailer->sendVerificationEmail(
                    (string)$user['email'],
                    (string)$user['display_name'],
                    $verifyUrl,
                );
                if ($sent) {
                    $this->flashSuccess(
                        $role === 'teacher'
                            ? '帳號已建立。請至 Email 點擊驗證連結；驗證後仍須管理員審核才可登入。'
                            : '帳號已建立。請至 Email 點擊驗證連結啟用帳號。'
                    );
                } else {
                    $this->flashSuccess(
                        '帳號已建立，但驗證信寄送失敗，請聯絡管理員（Email 服務暫時無法使用）。'
                    );
                }
            } else {
                $this->flashSuccess(
                    $role === 'teacher'
                        ? '帳號已建立，但您未填 Email，需聯絡管理員完成啟用。'
                        : '帳號已建立，但您未填 Email，無法完成啟用流程（請聯絡管理員）。'
                );
            }
            return Response::redirect('/login');
        } catch (\InvalidArgumentException $e) {
            $this->flashError($e->getMessage());
            $_SESSION['_old'] = $req->post;
            return Response::redirect('/register?role=' . $role);
        }
    }

    /* ===== Login ===== */

    public function showLogin(Request $req): Response
    {
        // Always generate a fresh captcha on GET /login so the form has one to display.
        $this->captcha->generate();
        return $this->view($req, 'auth/login', [
            'title' => '登入 — CTF LAB',
            'old' => $this->sessionOld(),
            'intended' => $_SESSION['intended_url'] ?? null,
            'locked' => $this->captcha->isLocked(),
            'lock_seconds' => $this->captcha->lockoutRemainingSeconds(),
        ]);
    }

    public function login(Request $req): Response
    {
        // 1. Per-browser lockout check (highest priority)
        if ($this->captcha->isLocked()) {
            $remaining = $this->captcha->lockoutRemainingSeconds();
            $minutes = (int)\ceil($remaining / 60);
            $this->flashError("此瀏覽器已被鎖定，請於 {$minutes} 分鐘後再試");
            return Response::redirect('/login');
        }

        $username   = trim((string)($req->post['username'] ?? ''));
        $password   = (string)($req->post['password'] ?? '');
        $captchaInp = trim((string)($req->post['captcha'] ?? ''));

        if ($username === '' || $password === '' || $captchaInp === '') {
            $this->flashError('請輸入帳號、密碼與認證碼');
            $_SESSION['_old'] = ['username' => $username];
            return Response::redirect('/login');
        }

        // 2. CAPTCHA check (does NOT count toward lockout)
        if (!$this->captcha->verify($captchaInp)) {
            $this->flashError('認證碼錯誤，請重新輸入');
            $_SESSION['_old'] = ['username' => $username];
            return Response::redirect('/login');
        }

        // 3. Credential check (counts toward lockout)
        $user = $this->auth->attemptLogin($username, $password);
        if (!$user) {
            $count = $this->captcha->recordFail();
            $existing = $this->users->findByUsername($username);
            if ($count >= $this->captcha->maxFails()) {
                $mins = (int)\ceil($this->captcha->lockSeconds() / 60);
                $this->flashError("連續 {$count} 次錯誤，此瀏覽器已被鎖定 {$mins} 分鐘");
                \CTF\Server\Services\AuditLog::fromRequest($req, 'login_locked', 'user', isset($existing['id']) ? (string)$existing['id'] : null, [
                    'username' => $username,
                    'reason'   => 'too_many_failed_attempts',
                ]);
            } else {
                $remaining = $this->captcha->maxFails() - $count;
                $reason = 'invalid_credentials';
                if ($existing && $existing['status'] === UserRepository::STATUS_PENDING) {
                    $reason = 'pending_admin_approval';
                    $this->flashError('此帳號尚待管理員審核');
                } elseif ($existing && $existing['status'] === UserRepository::STATUS_DISABLED) {
                    $reason = 'disabled';
                    $this->flashError('此帳號已被停用');
                } else {
                    $this->flashError("帳號或密碼錯誤（剩餘 {$remaining} 次機會）");
                }
                \CTF\Server\Services\AuditLog::fromRequest($req, 'login_failed', 'user', isset($existing['id']) ? (string)$existing['id'] : null, [
                    'username' => $username,
                    'reason'   => $reason,
                ]);
            }
            $_SESSION['_old'] = ['username' => $username];
            return Response::redirect('/login');
        }

        // 4. Success — clear lockout counters
        $this->captcha->clearFails();
        $this->auth->login($user, $req);

        return Response::redirect('/');
    }

    public function logout(Request $req): Response
    {
        // Clear intended_url to prevent post-logout redirect loops
        unset($_SESSION['intended_url']);
        $this->auth->logout($req);
        return Response::redirect('/login');
    }

    /* ===== Email verification ===== */

    public function showVerifyEmail(Request $req): Response
    {
        $token = (string)($req->query['token'] ?? '');
        if ($token === '') {
            return $this->view($req, 'auth/verify_email_result', [
                'title' => '驗證 Email — CTF LAB',
                'ok' => false,
                'reason' => 'missing_token',
            ]);
        }
        $user = $this->verification->verify($token);
        if (!$user) {
            return $this->view($req, 'auth/verify_email_result', [
                'title' => '驗證 Email — CTF LAB',
                'ok' => false,
                'reason' => 'invalid_or_expired',
            ]);
        }
        // Audit
        \CTF\Server\Services\AuditLog::fromRequest(
            $req,
            'email_verified',
            'user',
            (string)$user['id'],
            ['role' => $user['role'], 'status' => $user['status']]
        );
        return $this->view($req, 'auth/verify_email_result', [
            'title' => '驗證 Email — CTF LAB',
            'ok' => true,
            'user_role' => $user['role'],
            'user_status' => $user['status'],
        ]);
    }

    /* ===== Password reset ===== */

    public function showResetRequest(Request $req): Response
    {
        return $this->view($req, 'auth/password_reset_request', [
            'title' => '重設密碼 — CTF LAB',
            'old' => $this->sessionOld(),
        ]);
    }

    public function resetRequest(Request $req): Response
    {
        $identifier = trim((string)($req->post['identifier'] ?? ''));
        if ($identifier === '') {
            $this->flashError('請輸入帳號或 Email');
            $_SESSION['_old'] = ['identifier' => $identifier];
            return Response::redirect('/password/reset');
        }
        $token = $this->passwordReset->requestReset($identifier);
        if ($token !== null) {
            $user = $this->users->findByUsername($identifier)
                    ?? $this->users->findByEmail($identifier);
            if ($user && !empty($user['email'])) {
                $ttl = (int)\CTF\Server\Support\Config::get('PASSWORD_RESET_TTL', 60);
                $resetUrl = $this->absoluteUrl('/password/reset/confirm') . '?token=' . $token;
                $this->mailer->sendPasswordResetEmail(
                    (string)$user['email'],
                    (string)$user['display_name'],
                    $resetUrl,
                    $ttl,
                );
            }
            // We don't leak whether the account exists. Log a separate audit row.
            \CTF\Server\Services\AuditLog::fromRequest(
                $req,
                'password_reset_request',
                'user',
                isset($user['id']) ? (string)$user['id'] : null,
                ['sent' => $token !== null]
            );
        }
        // Always respond with success message to avoid enumeration.
        $this->flashSuccess('如果該帳號存在且已驗證 Email，重設連結已寄出（請於 ' .
            (int)Config::get('PASSWORD_RESET_TTL', 60) . ' 分鐘內點擊）');
        return Response::redirect('/login');
    }

    public function showResetConfirm(Request $req): Response
    {
        $token = (string)($req->query['token'] ?? '');
        return $this->view($req, 'auth/password_reset_confirm', [
            'title' => '重設密碼 — CTF LAB',
            'token' => $token,
            'valid' => $this->passwordReset->isValid($token),
        ]);
    }

    public function resetConfirm(Request $req): Response
    {
        $token    = (string)($req->post['token'] ?? '');
        $password = (string)($req->post['password'] ?? '');
        $confirm  = (string)($req->post['password_confirm'] ?? '');

        if ($token === '' || !$this->passwordReset->isValid($token)) {
            $this->flashError('連結無效或已過期，請重新申請');
            return Response::redirect('/password/reset');
        }
        if ($password === '' || $confirm === '') {
            $this->flashError('請填寫新密碼與確認密碼');
            return Response::redirect('/password/reset/confirm?token=' . urlencode($token));
        }
        if ($password !== $confirm) {
            $this->flashError('兩次密碼輸入不一致');
            return Response::redirect('/password/reset/confirm?token=' . urlencode($token));
        }
        try {
            $userId = $this->passwordReset->reset($token, $password);
            if (!$userId) {
                $this->flashError('連結無效或已過期');
                return Response::redirect('/password/reset');
            }
            \CTF\Server\Services\AuditLog::fromRequest(
                $req,
                'password_reset',
                'user',
                (string)$userId,
            );
            $this->flashSuccess('密碼已重設，請用新密碼登入');
            return Response::redirect('/login');
        } catch (\InvalidArgumentException $e) {
            $this->flashError($e->getMessage());
            return Response::redirect('/password/reset/confirm?token=' . urlencode($token));
        }
    }

    /* ===== API ===== */

    public function me(Request $req): Response
    {
        $user = $_SESSION['user'] ?? null;
        if (!$user) {
            return $this->jsonError('Unauthorized', 401);
        }
        return $this->jsonOk($user);
    }

    /* ===== Helpers ===== */

    private function sessionOld(): array
    {
        $old = $_SESSION['_old'] ?? [];
        unset($_SESSION['_old']);
        return is_array($old) ? $old : [];
    }

    private function dashboardUrl(string $role): string
    {
        return match ($role) {
            'admin' => '/admin',
            'teacher' => '/teacher',
            default => '/student',
        };
    }

    private function absoluteUrl(string $path): string
    {
        $base = rtrim((string)Config::get('APP_URL', ''), '/');
        return $base . '/' . ltrim($path, '/');
    }
}
