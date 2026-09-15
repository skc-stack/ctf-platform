<?php
/**
 * tests/e2e_password_reset.php — Smoke test for password reset flow.
 *
 * Run:
 *   php tests/e2e_password_reset.php
 *
 * Verifies:
 *   - /password/reset form renders
 *   - POST /password/reset with valid identifier returns success flash
 *   - Reset token can be retrieved from DB
 *   - /password/reset/confirm shows form when token valid
 *   - POST with new password updates users.password_hash
 *   - Old password no longer works
 *   - New password works
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use CTF\Server\Database\Connection;
use CTF\Server\Security\PasswordHasher;

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

echo "\n=== Password Reset E2E ===\n\n";

// Clear rate-limit buckets (we may share the IP bucket with other tests)
Connection::run('DELETE FROM rate_limits');
Connection::run('DELETE FROM password_reset_tokens');

// Set up: ensure admin exists with a known password and verified email.
$adminRow = Connection::fetchOne('SELECT id FROM users WHERE username = ?', ['admin']);
if (!$adminRow) {
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
Connection::run('UPDATE users SET email_verified_at = NOW() WHERE id = :id', [':id' => $adminRow['id']]);
$oldHash = Connection::fetchOne('SELECT password_hash FROM users WHERE id = :id', [':id' => $adminRow['id']])['password_hash'];

echo "[Request reset]\n";
$r = http('GET', '/password/reset');
if ($r['status'] !== 200 || !str_contains($r['body'], '重設密碼')) fail('GET /password/reset');
pass('GET /password/reset form renders');

$csrf = extractCsrf($r['body']);
$r = http('POST', '/password/reset', $r['setCookies'], ['_csrf' => $csrf, 'identifier' => 'admin'], $csrf);
if ($r['status'] !== 302 || $r['location'] !== '/login') fail('POST /password/reset', "status {$r['status']} loc=" . ($r['location'] ?? 'none'));
pass('POST /password/reset → /login (generic success, no enumeration)');

// Retrieve the reset token directly from DB (simulating email click)
$tokenRow = Connection::fetchOne(
    'SELECT token_hash FROM password_reset_tokens
     WHERE user_id = :u AND used_at IS NULL AND expires_at > NOW()
     ORDER BY id DESC LIMIT 1',
    [':u' => $adminRow['id']]
);
if (!$tokenRow) fail('reset token issued', 'no token row found in DB');
pass('reset token row exists in DB');

echo "\n[Confirm reset]\n";

// We can't reconstruct the raw token from its hash; for the test we
// cheat by issuing a fresh token via the service and using that.
$svc = new \CTF\Server\Services\PasswordResetService();
// Use reflection to grab the freshly-issued raw token from a side channel;
// easier: just call requestReset again to get a usable raw token.
$raw = $svc->requestReset('admin');
if (!$raw) fail('service.requestReset');
pass('service.issue raw token (simulating user clicking email link)');

$r = http('GET', '/password/reset/confirm?token=' . urlencode($raw));
if ($r['status'] !== 200 || !str_contains($r['body'], '重設密碼')) fail('GET /password/reset/confirm');
if (!str_contains($r['body'], '新密碼')) fail('GET /password/reset/confirm', 'form fields not rendered');
pass('GET /password/reset/confirm shows form');

$csrf = extractCsrf($r['body']);
$newPassword = 'Reset99AA';
$r = http('POST', '/password/reset/confirm', $r['setCookies'], [
    '_csrf' => $csrf,
    'token' => $raw,
    'password' => $newPassword,
    'password_confirm' => $newPassword,
], $csrf);
if ($r['status'] !== 302 || $r['location'] !== '/login') fail('POST /password/reset/confirm', "status {$r['status']} loc=" . ($r['location'] ?? 'none'));
pass('POST /password/reset/confirm → /login (success)');

$newHash = Connection::fetchOne('SELECT password_hash FROM users WHERE id = :id', [':id' => $adminRow['id']])['password_hash'];
if ($newHash === $oldHash) fail('password hash changed', 'hash unchanged after reset');
if (!PasswordHasher::verify($newPassword, $newHash)) fail('new password verifies', 'verify() failed');
if (PasswordHasher::verify('Admin1234', $newHash)) fail('old password rejected', 'old password still works');
pass('password hash updated; old password rejected; new password works');

echo "\n=== ALL PASS ===\n";
