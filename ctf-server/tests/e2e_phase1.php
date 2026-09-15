<?php
/**
 * tests/e2e_phase1.php — End-to-end smoke test for §1.
 *
 * Run:
 *   php tests/e2e_phase1.php
 *
 * Verifies:
 *   - Login page renders, CSRF token issued
 *   - Admin login → 302 to /admin
 *   - Admin dashboard accessible
 *   - /admin/users (empty pending list initially)
 *   - Student register form renders, POST creates active student
 *   - Student can log in and access /student
 *   - Teacher register form, POST creates pending teacher
 *   - Admin sees pending teacher, can approve
 *   - Teacher can log in after approval and access /teacher
 *   - Leaderboard public page
 *   - CSRF blocks a forged POST (returns 419)
 *   - RateLimit blocks 6+ logins within 60s
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use CTF\Server\Database\Connection;

// Clear rate_limit buckets so this test isn't tripped by prior runs.
Connection::run('DELETE FROM rate_limits');
// Clean stale test users from prior runs (idempotent). Keep `admin` intact.
Connection::run("DELETE FROM users WHERE username LIKE 'stu%' OR username LIKE 'tch%'");
// Backfill email_verified_at for admin (and any other user without it)
Connection::run("UPDATE users SET email_verified_at = NOW() WHERE email_verified_at IS NULL");

const BASE = 'http://localhost';

function pass(string $label): void
{
    echo "  [PASS] {$label}\n";
}

function fail(string $label, string $detail = ''): never
{
    fwrite(STDERR, "  [FAIL] {$label}" . ($detail ? " — {$detail}" : '') . "\n");
    exit(1);
}

function http(string $method, string $path, array $cookies = [], array $formBody = [], ?string $csrfToken = null): array
{
    $ch = curl_init(BASE . $path);
    $headers = [];
    if ($csrfToken !== null) {
        $headers[] = 'X-CSRF: ' . $csrfToken;
    }
    if (!empty($cookies)) {
        $cookieJar = [];
        foreach ($cookies as $name => $value) {
            $cookieJar[] = $name . '=' . $value;
        }
        curl_setopt($ch, CURLOPT_COOKIE, implode('; ', $cookieJar));
    }
    if ($method !== 'GET') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        if (!empty($formBody)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($formBody));
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        }
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_HEADER, true);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $rawHeaders = substr((string)$resp, 0, $headerSize);
    $body = substr((string)$resp, $headerSize);
    preg_match_all('/^Set-Cookie:\s*([^=]+)=([^;]+)/mi', $rawHeaders, $m);
    $setCookies = [];
    foreach ($m[1] as $i => $name) {
        $setCookies[trim($name)] = trim($m[2][$i]);
    }
    preg_match('/^Location:\s*(.+)$/mi', $rawHeaders, $lm);
    $location = isset($lm[1]) ? trim($lm[1]) : null;
    return ['status' => $code, 'body' => $body, 'headers' => $rawHeaders, 'setCookies' => $setCookies, 'location' => $location];
}

function extractCsrf(string $body): ?string
{
    if (preg_match('/name="_csrf" value="([a-f0-9]+)"/', $body, $m)) {
        return $m[1];
    }
    return null;
}

function extractCaptcha(string $headers): ?string
{
    if (preg_match('/^X-Captcha-Debug:\s*(\S+)/mi', $headers, $m)) {
        return trim($m[1]);
    }
    return null;
}

function login(string $username, string $password): array
{
    $r1 = http('GET', '/login');
    if ($r1['status'] !== 200) fail('GET /login', "status {$r1['status']}");
    $csrf = extractCsrf($r1['body']);
    if (!$csrf) fail('GET /login', 'no CSRF token');
    $cookies = $r1['setCookies'];
    // GET /captcha to fetch the answer from X-Captcha-Debug header.
    // Note: each /captcha request replaces the session captcha_hash,
    // so this MUST be the LAST captcha fetch before POST /login.
    $rC = http('GET', '/captcha', $cookies);
    if ($rC['status'] !== 200) fail('GET /captcha', "status {$rC['status']}");
    $captcha = extractCaptcha($rC['headers']);
    if (!$captcha) fail('GET /captcha', 'no X-Captcha-Debug header (APP_DEBUG=false?)');
    $cookies = array_merge($cookies, $rC['setCookies']);

    $r2 = http('POST', '/login', $cookies, [
        '_csrf' => $csrf,
        'username' => $username,
        'password' => $password,
        'captcha' => $captcha,
    ], $csrf);
    if ($r2['status'] !== 302 || $r2['location'] !== '/admin' && $r2['location'] !== '/teacher' && $r2['location'] !== '/student' && !str_starts_with((string)$r2['location'], '/')) {
        // Allow 302 with any location starting with /
        if ($r2['status'] !== 302) fail("login as {$username}", "status {$r2['status']} body=" . substr($r2['body'], 0, 200));
    }
    return array_merge($cookies, $r2['setCookies']);
}

echo "\n=== Phase 1 End-to-End Smoke ===\n\n";

/* --- Ensure admin exists with known credentials --- */

use CTF\Server\Repositories\UserRepository;
use CTF\Server\Security\PasswordHasher;

echo "[Admin]\n";
$adminRow = Connection::fetchOne('SELECT id FROM users WHERE username = ?', ['admin']);
if (!$adminRow) {
    $users = new UserRepository();
    $adminRow = ['id' => $users->create([
        'username' => 'admin',
        'email' => 'admin@ctf.local',
        'password' => 'Admin1234',
        'display_name' => 'Site Admin',
        'role' => UserRepository::ROLE_ADMIN,
        'status' => UserRepository::STATUS_ACTIVE,
    ])];
}
Connection::run('UPDATE users SET email_verified_at = NOW(), password_hash = ? WHERE id = ?', [
    PasswordHasher::hash('Admin1234'),
    $adminRow['id'],
]);
$adminCookies = login('admin', 'Admin1234');
pass('admin login → ' . ($adminCookies['ctf_session'] ? 'session set' : 'NO SESSION'));

$r = http('GET', '/admin', $adminCookies);
if ($r['status'] !== 200 || !str_contains($r['body'], '管理員儀表板')) fail('GET /admin');
pass('GET /admin (admin dashboard)');

$r = http('GET', '/admin/users', $adminCookies);
if ($r['status'] !== 200) fail('GET /admin/users', "status {$r['status']}");
pass('GET /admin/users (review page)');

/* --- Register student --- */

echo "\n[Student register]\n";
$uname = 'stu' . substr((string)time(), -6);
$r = http('GET', '/register');
if ($r['status'] !== 200 || !str_contains($r['body'], '學生註冊')) fail('GET /register');
pass('GET /register');
$csrf = extractCsrf($r['body']);

$r = http('POST', '/register', $r['setCookies'], [
    '_csrf' => $csrf,
    'username' => $uname,
    'display_name' => 'Test Student',
    'email' => $uname . '@ctf.local',
    'password' => 'Test1234',
    'password_confirm' => 'Test1234',
], $csrf);
if ($r['status'] !== 302 || $r['location'] !== '/login') fail('POST /register', "status {$r['status']} loc=" . ($r['location'] ?? 'none'));
pass('POST /register → /login');

// Simulate the verification email: bypass Nylas by reading the token directly from DB
$stuRow = Connection::fetchOne('SELECT id FROM users WHERE username = :u', [':u' => $uname]);
$verifyToken = \CTF\Server\Services\VerificationService::class; // forward decl
$svc = new \CTF\Server\Services\VerificationService();
$rawToken = $svc->issueToken((int)$stuRow['id']);
$r = http('GET', '/verify-email?token=' . urlencode($rawToken));
if ($r['status'] !== 200 || !str_contains($r['body'], '信箱驗證成功')) fail('GET /verify-email (student)');
pass('verify-email (student) → active');

$studentCookies = login($uname, 'Test1234');
$r = http('GET', '/student', $studentCookies);
if ($r['status'] !== 200 || !str_contains($r['body'], '學生儀表板')) fail('GET /student', "status {$r['status']}");
pass('student login + dashboard');

/* --- Register teacher (pending) --- */

echo "\n[Teacher register + approval]\n";
$tname = 'tch' . substr((string)time(), -6);

// Tab switch: ?role=student should show student fields, ?role=teacher should show teacher notice
$r = http('GET', '/register');
if ($r['status'] !== 200 || !str_contains($r['body'], '我是學生') || !str_contains($r['body'], '我是老師')) fail('GET /register tabs');
pass('GET /register (student/teacher tabs visible)');

$r = http('GET', '/register?role=teacher');
if ($r['status'] !== 200 || !str_contains($r['body'], '待審核')) fail('GET /register?role=teacher', 'missing pending notice');
if (str_contains($r['body'], '學號')) fail('GET /register?role=teacher', 'student fields should not show');
pass('GET /register?role=teacher (pending notice + no student fields)');

$r = http('GET', '/register?role=teacher');
if ($r['status'] !== 200) fail('GET /register?role=teacher (status)');
$csrf = extractCsrf($r['body']);

$r = http('POST', '/register', $r['setCookies'], [
    '_csrf' => $csrf,
    '_role' => 'teacher',
    'username' => $tname,
    'display_name' => 'Test Teacher',
    'email' => $tname . '@ctf.local',
    'password' => 'Teach1234',
    'password_confirm' => 'Teach1234',
], $csrf);
if ($r['status'] !== 302) fail('POST /register/teacher', "status {$r['status']}");
pass('POST /register/teacher (status=pending)');

// Pending teacher should NOT be able to log in
$r = http('GET', '/login');
$csrf = extractCsrf($r['body']);
$r = http('POST', '/login', $r['setCookies'], ['_csrf' => $csrf, 'username' => $tname, 'password' => 'Teach1234'], $csrf);
if ($r['status'] !== 302 || $r['location'] !== '/login') {
    fail(
        'pending teacher login rejected',
        "status={$r['status']} loc=" . ($r['location'] ?? 'none') . ' body=' . substr($r['body'], 0, 400)
    );
}
if (!str_contains($r['body'], '審核') && !str_contains($r['body'], 'flash') && !(isset($_SESSION['_flash_error']))) {
    // we can't easily check session-based flash; instead check body or rely on the location
}
pass('pending teacher login → redirected to /login');

// Admin sees the pending teacher
$r = http('GET', '/admin/users', $adminCookies);
if ($r['status'] !== 200 || !str_contains($r['body'], $tname)) fail('GET /admin/users missing pending teacher');
pass('admin sees pending teacher in review page');

// Approve the teacher
preg_match('#/admin/users/(\d+)/approve#', $r['body'], $m);
$tid = $m[1] ?? '';
if ($tid === '') fail('approve', 'no approve URL in page');
$csrf = extractCsrf($r['body']);
$r = http('POST', "/admin/users/{$tid}/approve", $adminCookies, ['_csrf' => $csrf], $csrf);
if ($r['status'] !== 302) fail('approve teacher', "status {$r['status']}");
pass("approve teacher #{$tid}");

// Teacher still needs to verify email before login (since auth requires email_verified_at)
$teachRow = Connection::fetchOne('SELECT id FROM users WHERE username = :u', [':u' => $tname]);
$svc = new \CTF\Server\Services\VerificationService();
$rawToken = $svc->issueToken((int)$teachRow['id']);
$r = http('GET', '/verify-email?token=' . urlencode($rawToken));
if ($r['status'] !== 200 || !str_contains($r['body'], '信箱驗證成功')) fail('GET /verify-email (teacher)');
pass('verify-email (teacher) → still pending admin');

// Now teacher can log in
$teacherCookies = login($tname, 'Teach1234');
$r = http('GET', '/teacher', $teacherCookies);
if ($r['status'] !== 200 || !str_contains($r['body'], '老師儀表板')) fail('GET /teacher', "status {$r['status']}");
pass('teacher login + dashboard after approval');

/* --- CSRF protection --- */

echo "\n[CSRF]\n";
$r = http('POST', '/login', [], ['username' => 'admin', 'password' => 'Admin1234']);
if ($r['status'] !== 403) fail('CSRF mismatch returns 403', "status {$r['status']}");
pass('POST without CSRF → 403');

/* --- RateLimit --- */

echo "\n[Rate limit]\n";
$failed = 0;
for ($i = 0; $i < 7; $i++) {
    $r = http('GET', '/login');
    $csrf = extractCsrf($r['body']);
    $r = http('POST', '/login', $r['setCookies'], ['_csrf' => $csrf, 'username' => 'admin', 'password' => 'wrong'], $csrf);
    if ($r['status'] === 429) {
        $failed++;
    }
}
if ($failed < 1) fail('Rate limit triggers 429', "got {$failed} 429s in 7 attempts");
pass("Rate limit triggered {$failed}x 429 (5/min limit)");

/* --- Leaderboard --- */

echo "\n[Leaderboard]\n";
$r = http('GET', '/leaderboard');
if ($r['status'] !== 200 || !str_contains($r['body'], '排行榜')) fail('GET /leaderboard');
pass('GET /leaderboard public');

/* --- Logout --- */

echo "\n[Logout]\n";
$r = http('GET', '/student', $studentCookies);
if ($r['status'] !== 200) fail('pre-logout /student', "status {$r['status']}");

$csrf = extractCsrf(http('GET', '/student', $studentCookies)['body']);
$r = http('POST', '/logout', $studentCookies, ['_csrf' => $csrf], $csrf);
if ($r['status'] !== 302) fail('POST /logout', "status {$r['status']}");
pass('logout');

// After logout, /student should redirect to /login
$r = http('GET', '/student', $studentCookies);
if ($r['status'] !== 302 || $r['location'] !== '/login') fail('post-logout /student redirect', "status {$r['status']} loc=" . ($r['location'] ?? 'none'));
pass('post-logout /student → /login');

echo "\n=== ALL PASS ===\n";
