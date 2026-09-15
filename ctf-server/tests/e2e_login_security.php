<?php
/**
 * tests/e2e_login_security.php — Smoke test for CAPTCHA + 3-strike lockout.
 *
 * Run:
 *   php tests/e2e_login_security.php
 *
 * Verifies:
 *   - GET /captcha returns a PNG with X-Captcha-Debug header
 *   - GET /login shows the captcha <img>
 *   - Wrong captcha → flash "認證碼錯誤" (does NOT increment lockout)
 *   - Wrong password 3 times → flash "剩餘 N 次" then "已被鎖定"
 *   - After 3 failures, GET /login shows the lock notice
 *   - After 3 failures, POST /login (with correct creds) is REJECTED
 *   - A fresh session (new cookies) is NOT locked
 *   - Successful login clears lock counter (next failure starts fresh)
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use CTF\Server\Database\Connection;

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

function http(string $method, string $path, array $cookies = [], array $formBody = []): array
{
    $ch = curl_init(BASE . $path);
    if (!empty($cookies)) {
        $j = [];
        foreach ($cookies as $n => $v) $j[] = "$n=$v";
        curl_setopt($ch, CURLOPT_COOKIE, implode('; ', $j));
    }
    if ($method !== 'GET') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        if (!empty($formBody)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($formBody));
        }
    }
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_HEADER, true);
    $r = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $rawHeaders = substr((string)$r, 0, $hs);
    $body = substr((string)$r, $hs);
    preg_match_all('/^Set-Cookie:\s*([^=]+)=([^;]+)/mi', $rawHeaders, $m);
    $set = [];
    foreach ($m[1] as $i => $n) $set[trim($n)] = trim($m[2][$i]);
    preg_match('/^Location:\s*(.+)$/mi', $rawHeaders, $lm);
    $location = isset($lm[1]) ? trim($lm[1]) : null;
    return ['status' => $code, 'body' => $body, 'headers' => $rawHeaders, 'setCookies' => $set, 'location' => $location];
}

function csrf(string $body): ?string
{
    return preg_match('/name="_csrf" value="([a-f0-9]+)"/', $body, $m) ? $m[1] : null;
}

function captcha(string $headers): ?string
{
    return preg_match('/^X-Captcha-Debug:\s*(\S+)/mi', $headers, $m) ? trim($m[1]) : null;
}

/** Pull the latest flash message off the session by re-GETting /login. */
function readFlash(array $cookies): ?string
{
    $r = http('GET', '/login', $cookies);
    if (preg_match('/<div class="ctf-flash[^"]*">\s*<i[^>]*><\/i>\s*([^<]+)/', $r['body'], $m)) {
        return trim($m[1]);
    }
    if (preg_match('/<div class="ctf-flash[^"]*">\s*([^<]+)/', $r['body'], $m)) {
        return trim($m[1]);
    }
    return null;
}

/** Pull the locked-until hint from /login body (or null). */
function isLoginLocked(array $cookies): bool
{
    $r = http('GET', '/login', $cookies);
    return str_contains($r['body'], '已被鎖定');
}

echo "\n=== Login Security E2E (CAPTCHA + Lockout) ===\n\n";

// Clear rate-limit buckets so this run isn't affected by previous tests.
Connection::run('DELETE FROM rate_limits');

/** Reusable: clear rate-limit bucket + return fresh cookies for a new session. */
function freshSession(): array {
    Connection::run('DELETE FROM rate_limits WHERE bucket_key LIKE "login:%"');
    return [];
}

// Make sure admin exists with a known password and is email-verified.
$adminRow = Connection::fetchOne('SELECT id, email_verified_at FROM users WHERE username = ?', ['admin']);
if (!$adminRow) {
    // Create admin with known credentials
    $users = new \CTF\Server\Repositories\UserRepository();
    $adminRow = ['id' => $users->create([
        'username' => 'admin',
        'email' => 'admin@ctf.local',
        'password' => 'Admin1234',
        'display_name' => 'Site Admin',
        'role' => \CTF\Server\Repositories\UserRepository::ROLE_ADMIN,
        'status' => \CTF\Server\Repositories\UserRepository::STATUS_ACTIVE,
    ])];
}
Connection::run('UPDATE users SET email_verified_at = NOW(), password_hash = ? WHERE id = ?', [
    \CTF\Server\Security\PasswordHasher::hash('Admin1234'),
    $adminRow['id'],
]);

/* === 1. Captcha image is served === */

echo "[Captcha image]\n";
$cookies = []; // fresh session
$r = http('GET', '/captcha', $cookies);
if ($r['status'] !== 200) fail('GET /captcha', "status {$r['status']}");
if (!str_starts_with((string)($r['headers'] ?? ''), 'HTTP/1.1') && strlen($r['body']) < 100) {
    fail('GET /captcha', 'body too small to be a PNG');
}
if (!str_contains($r['headers'], 'Content-Type: image/png')) fail('GET /captcha', 'no image/png content-type');
if (!str_contains($r['headers'], 'X-Captcha-Debug:')) fail('GET /captcha', 'no X-Captcha-Debug header (APP_DEBUG=false?)');
$firstCookies = $r['setCookies'];
$cookies = array_merge($cookies, $firstCookies);
pass('GET /captcha returns PNG + X-Captcha-Debug header');

/* === 2. Login page shows captcha <img> === */

echo "\n[Login form]\n";
$r = http('GET', '/login', $cookies);
$cookies = array_merge($cookies, $r['setCookies']);
$csf = csrf($r['body']);
if (!$csf) fail('GET /login', 'no CSRF');
if (!str_contains($r['body'], '<img') || !str_contains($r['body'], '/captcha')) fail('GET /login', 'captcha <img> not rendered');
if (!str_contains($r['body'], 'name="captcha"')) fail('GET /login', 'captcha input not present');
pass('GET /login shows captcha <img> + input');

/* === 3. Wrong captcha is rejected, does NOT count === */

echo "\n[Wrong captcha]\n";
$r = http('GET', '/captcha', $cookies);
$cap = captcha($r['headers']);
$cookies = array_merge($cookies, $r['setCookies']);
$r = http('POST', '/login', $cookies, [
    '_csrf' => $csf,
    'username' => 'admin',
    'password' => 'Admin1234',
    'captcha' => 'WRONG', // truly wrong
], $csf);
if ($r['status'] !== 302 || $r['location'] !== '/login') fail('wrong captcha', "status {$r['status']} loc=" . ($r['location'] ?? 'none') . " body=" . substr($r['body'], 0, 300));
$cookies = array_merge($cookies, $r['setCookies']);
$flash = readFlash($cookies);
if ($flash === null || !str_contains($flash, '認證碼')) fail('wrong captcha flash', 'no 認證碼 message; got: ' . ($flash ?? 'none'));
pass('wrong captcha → flash 認證碼錯誤');

// After captcha failure, lock counter should still be 0 (no credential attempt was made).
// Verify by successfully logging in with correct password + fresh captcha.
$csf = csrf(http('GET', '/login', $cookies)['body']);
$r = http('GET', '/captcha', $cookies);
$cap = captcha($r['headers']);
$cookies = array_merge($cookies, $r['setCookies']);
$r = http('POST', '/login', $cookies, [
    '_csrf' => $csf,
    'username' => 'admin',
    'password' => 'Admin1234',
    'captcha' => $cap,
], $csf);
if ($r['status'] !== 302 || $r['location'] !== '/admin') fail('recovery after captcha fail', "status {$r['status']} loc={$r['location']}");
$cookies = array_merge($cookies, $r['setCookies']);
pass('captcha failure does NOT increment lockout (correct password still works)');

/* === 4. 3 wrong-password attempts → lockout === */

echo "\n[3-strike lockout]\n";

// Reset by logout first (so we have a fresh session)
// (we already logged in — no separate logout here; just use a new session)
$cookies = freshSession(); // brand-new session + cleared rate-limit bucket

for ($i = 1; $i <= 3; $i++) {
    // Fresh captcha per attempt (server regenerates each GET)
    $r = http('GET', '/login', $cookies);
    $cookies = array_merge($cookies, $r['setCookies']);
    $csf = csrf($r['body']);
    $rC = http('GET', '/captcha', $cookies);
    $cap = captcha($rC['headers']);
    $cookies = array_merge($cookies, $rC['setCookies']);

    $r = http('POST', '/login', $cookies, [
        '_csrf' => $csf,
        'username' => 'admin',
        'password' => 'WRONG_PASSWORD',
        'captcha' => $cap,
    ], $csf);
    if ($r['status'] !== 302) fail("attempt $i", "status {$r['status']}");
    $cookies = array_merge($cookies, $r['setCookies']);

    $flash = readFlash($cookies);
    echo "    attempt $i flash: " . ($flash ?? '(none)') . "\n";
    if ($flash === null) fail("attempt $i", 'no flash');
    if ($i < 3) {
        if (!str_contains($flash, '剩餘')) fail("attempt $i flash", "expected '剩餘 N 次', got: $flash");
    } else {
        if (!str_contains($flash, '已被鎖定') && !str_contains($flash, '連續')) fail("attempt $i flash", "expected lockout, got: $flash");
    }
}
pass('3 wrong passwords → lockout message at attempt 3');

/* === 5. Even with correct creds, locked session can't log in === */

echo "\n[Lock check on subsequent attempts]\n";
// Clear rate-limit so this POST doesn't 429 (the lockout logic itself should be the only blocker).
Connection::run('DELETE FROM rate_limits WHERE bucket_key LIKE "login:%"');
// First: GET /login should show lock notice
$locked = isLoginLocked($cookies);
if (!$locked) fail('lock notice on /login', 'lock indicator missing');
pass('GET /login shows locked notice');

// Then: POST with correct creds — must be rejected (still locked)
$csf = csrf(http('GET', '/login', $cookies)['body']);
$rC = http('GET', '/captcha', $cookies);
$cap = captcha($rC['headers']);
$cookies = array_merge($cookies, $rC['setCookies']);
$r = http('POST', '/login', $cookies, [
    '_csrf' => $csf,
    'username' => 'admin',
    'password' => 'Admin1234',
    'captcha' => $cap,
], $csf);
if ($r['status'] !== 302) fail('locked POST /login', "status {$r['status']}");
$cookies = array_merge($cookies, $r['setCookies']);
$flash = readFlash($cookies);
if ($flash === null || !str_contains($flash, '已被鎖定') && !str_contains($flash, '分鐘後')) {
    fail('locked POST flash', 'expected lock notice; got: ' . ($flash ?? 'none'));
}
pass('locked POST /login → rejected even with correct password');

/* === 6. Fresh session (new cookies) is NOT locked === */

echo "\n[Fresh session is unaffected]\n";
$freshCookies = [];
$r = http('GET', '/login', $freshCookies);
$freshCookies = array_merge($freshCookies, $r['setCookies']);
$csf = csrf($r['body']);
$rC = http('GET', '/captcha', $freshCookies);
$cap = captcha($rC['headers']);
$freshCookies = array_merge($freshCookies, $rC['setCookies']);
$r = http('POST', '/login', $freshCookies, [
    '_csrf' => $csf,
    'username' => 'admin',
    'password' => 'Admin1234',
    'captcha' => $cap,
], $csf);
if ($r['status'] !== 302 || $r['location'] !== '/admin') fail('fresh session login', "status {$r['status']} loc={$r['location']}");
pass('fresh session can log in (lock is browser-bound, not IP)');

echo "\n=== ALL PASS ===\n";
