<?php
/**
 * tests/e2e_devices.php — End-to-end test for §4 Phase 3 Device API.
 *
 * Run:
 *   php tests/e2e_devices.php
 *
 * Covers:
 *   1. Student login → request activation code
 *   2. Activation code format matches ACT-XXXX-XXXX-XXXX
 *   3. Device calls /api/v1/device/activate with the code → gets device_id + device_token
 *   4. Device calls /api/v1/device/info with Bearer token → returns device info
 *   5. Device calls /api/v1/device/heartbeat → updates last_seen_at
 *   6. Wrong Bearer token → 401
 *   7. Student revokes device → next API call → 401
 *   8. Re-using activation code → 400 "already used"
 *   9. DB contains only token hash, never plain token
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use CTF\Server\Database\Connection;
use CTF\Server\Repositories\UserRepository;
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

function http(string $method, string $path, array $cookies = [], array $formBody = [], ?string $csrfToken = null, array $headers = [], array $jsonBody = null): array
{
    $ch = curl_init(BASE . $path);
    $hdrs = [];
    if ($csrfToken !== null) $hdrs[] = 'X-CSRF: ' . $csrfToken;
    foreach ($headers as $name => $val) $hdrs[] = "$name: $val";
    if (!empty($cookies)) {
        $jar = [];
        foreach ($cookies as $name => $value) $jar[] = "$name=$value";
        curl_setopt($ch, CURLOPT_COOKIE, implode('; ', $jar));
    }
    if ($method !== 'GET') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        if ($jsonBody !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($jsonBody));
            $hdrs[] = 'Content-Type: application/json';
        } elseif (!empty($formBody)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($formBody));
            $hdrs[] = 'Content-Type: application/x-www-form-urlencoded';
        }
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $hdrs);
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
    foreach ($m[1] as $i => $name) $setCookies[trim($name)] = trim($m[2][$i]);
    return ['status' => $code, 'body' => $body, 'headers' => $rawHeaders, 'setCookies' => $setCookies];
}

function extractCsrf(string $body): ?string
{
    if (preg_match('/name="_csrf" value="([a-f0-9]+)"/', $body, $m)) return $m[1];
    return null;
}

function extractCaptcha(string $headers): ?string
{
    if (preg_match('/^X-Captcha-Debug:\s*(\S+)/mi', $headers, $m)) return trim($m[1]);
    return null;
}

function login(string $username, string $password): array
{
    $r1 = http('GET', '/login');
    if ($r1['status'] !== 200) fail('GET /login', "status {$r1['status']}");
    $csrf = extractCsrf($r1['body']);
    $cookies = $r1['setCookies'];
    $rC = http('GET', '/captcha', $cookies);
    $captcha = extractCaptcha($rC['headers']);
    $cookies = array_merge($cookies, $rC['setCookies']);

    $r2 = http('POST', '/login', $cookies, [
        '_csrf' => $csrf,
        'username' => $username,
        'password' => $password,
        'captcha' => $captcha,
    ], $csrf);
    if ($r2['status'] !== 302) fail("login as {$username}", "status {$r2['status']} body=" . substr($r2['body'], 0, 200));
    return array_merge($cookies, $r2['setCookies']);
}

echo "\n=== Phase 3 End-to-End: Device API ===\n\n";

/* --- Clean --- */
Connection::run('DELETE FROM devices');
Connection::run('DELETE FROM device_activation_codes');
Connection::run('DELETE FROM rate_limits');

/* --- Ensure admin + create student --- */
$adminRow = Connection::fetchOne('SELECT id FROM users WHERE username = ?', ['admin']);
if (!$adminRow) {
    $users = new UserRepository();
    $adminRow = ['id' => $users->create([
        'username' => 'admin', 'email' => 'admin@ctf.local',
        'password' => 'Admin1234', 'display_name' => 'Site Admin',
        'role' => UserRepository::ROLE_ADMIN, 'status' => UserRepository::STATUS_ACTIVE,
    ])];
}
Connection::run('UPDATE users SET email_verified_at = NOW(), password_hash = ? WHERE id = ?', [
    PasswordHasher::hash('Admin1234'), $adminRow['id'],
]);

/* --- Create + verify a student --- */
$stuName = 'dev_s' . substr((string)time(), -6);
$r = http('GET', '/register');
$csrf = extractCsrf($r['body']);
$r = http('POST', '/register', $r['setCookies'], [
    '_csrf' => $csrf,
    'username' => $stuName,
    'display_name' => 'Device Student',
    'email' => $stuName . '@ctf.local',
    'password' => 'Test1234',
    'password_confirm' => 'Test1234',
], $csrf);
if ($r['status'] !== 302) fail('register student', "status {$r['status']}");

$stuRow = Connection::fetchOne('SELECT id FROM users WHERE username = :u', [':u' => $stuName]);
$svc = new \CTF\Server\Services\VerificationService();
$rawToken = $svc->issueToken((int)$stuRow['id']);
$r = http('GET', '/verify-email?token=' . urlencode($rawToken));
if ($r['status'] !== 200) fail('verify student');

$stuCookies = login($stuName, 'Test1234');
echo "[Setup] Student {$stuName} ready\n\n";

/* --- STEP 1+2: Generate activation code (server-side for testing) --- */
echo "[1+2] Generate activation code\n";
$acSrv = new \CTF\Server\Services\ActivationCodeService();
$actCode = $acSrv->generate((int)$stuRow['id']);
if (!preg_match('/^ACT-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/', $actCode)) {
    fail('activation code format', "got: $actCode");
}
pass("activation code format OK: $actCode");

/* --- STEP 3: Device activates --- */
echo "\n[3] Device calls /api/v1/device/activate\n";
$deviceUuid = sprintf('%08x-%04x-%04x-%04x-%012x',
    random_int(0, 0xffffffff), random_int(0, 0xffff),
    random_int(0, 0xffff), random_int(0, 0xffff),
    random_int(0, 0xffffffffffff));
$deviceName = 'Test Target VM';

$r = http('POST', '/api/v1/device/activate', [], [], null, [], [
    'activation_code' => $actCode,
    'device_uuid' => $deviceUuid,
    'device_name' => $deviceName,
]);
if ($r['status'] !== 200) fail('device/activate', "status {$r['status']} body=" . substr($r['body'], 0, 300));
$activateData = json_decode($r['body'], true);
if (!isset($activateData['data']['device_token']) || !isset($activateData['data']['device_id'])) {
    fail('device/activate response missing fields', $r['body']);
}
$deviceId = (int)$activateData['data']['device_id'];
$deviceToken = $activateData['data']['device_token'];
// Use server-assigned UUID for subsequent API calls (server ignores client-supplied UUID)
$deviceUuid = $activateData['data']['device_uuid'] ?? $deviceUuid;
pass("device activated (id={$deviceId}, uuid=" . substr($deviceUuid, 0, 8) . '..., token=' . substr($deviceToken, 0, 12) . '...)');

/* --- STEP 9: Verify DB has only token hash, not plain token --- */
echo "\n[9] DB stores only token hash\n";
$row = Connection::fetchOne('SELECT device_token_hash FROM devices WHERE id = :id', [':id' => $deviceId]);
$expectedHash = hash('sha256', $deviceToken);
if ($row['device_token_hash'] !== $expectedHash) fail('token hash mismatch');
if (str_contains($row['device_token_hash'], $deviceToken)) fail('plain token in DB!');
pass("only hash in DB; plain token not stored");

/* --- STEP 4: Device calls /info --- */
echo "\n[4] Device calls /api/v1/device/info\n";
$r = http('GET', '/api/v1/device/info', [], [], null, [
    'Authorization' => "Bearer {$deviceToken}",
    'X-Device-ID' => $deviceUuid,
]);
if ($r['status'] !== 200) fail('device/info', "status {$r['status']} body=" . substr($r['body'], 0, 200));
$infoData = json_decode($r['body'], true);
if ($infoData['data']['device_id'] !== $deviceId) fail('info device_id mismatch');
if ($infoData['data']['owner']['username'] !== $stuName) fail('info owner mismatch');
pass('device/info returns correct device + owner');

/* --- STEP 5: Heartbeat --- */
echo "\n[5] Device calls /api/v1/device/heartbeat\n";
$beforeRow = Connection::fetchOne('SELECT last_seen_at FROM devices WHERE id = :id', [':id' => $deviceId]);
$beforeTs = $beforeRow['last_seen_at'];
sleep(2);
$r = http('POST', '/api/v1/device/heartbeat', [], [], null, [
    'Authorization' => "Bearer {$deviceToken}",
    'X-Device-ID' => $deviceUuid,
], [
    'agent_version' => '1.2.3',
    'target_version' => 'ubuntu-24.04',
]);
if ($r['status'] !== 200) fail('heartbeat', "status {$r['status']} body=" . $r['body']);
$afterRow = Connection::fetchOne('SELECT last_seen_at, agent_version, target_version FROM devices WHERE id = :id', [':id' => $deviceId]);
if ($afterRow['last_seen_at'] === $beforeTs) fail('last_seen_at not updated');
if ($afterRow['agent_version'] !== '1.2.3') fail('agent_version not stored');
if ($afterRow['target_version'] !== 'ubuntu-24.04') fail('target_version not stored');
pass('heartbeat updates last_seen_at + agent_version + target_version');

/* --- STEP 6: Wrong token → 401 --- */
echo "\n[6] Wrong Bearer token → 401\n";
$r = http('GET', '/api/v1/device/info', [], [], null, [
    'Authorization' => 'Bearer wrong_token_here',
    'X-Device-ID' => $deviceUuid,
]);
if ($r['status'] !== 401) fail('wrong token should 401', "status {$r['status']}");
pass('wrong token rejected with 401');

/* --- STEP 8: Re-use activation code → 400 --- */
echo "\n[8] Re-use activation code → 400\n";
$actCode2 = $acSrv->generate((int)$stuRow['id']);
$deviceUuid2 = sprintf('%08x-%04x-%04x-%04x-%012x',
    random_int(0, 0xffffffff), random_int(0, 0xffff),
    random_int(0, 0xffff), random_int(0, 0xffff),
    random_int(0, 0xffffffffffff));
// Use it once
$r = http('POST', '/api/v1/device/activate', [], [], null, [], [
    'activation_code' => $actCode2,
    'device_uuid' => $deviceUuid2,
    'device_name' => 'First Target',
]);
if ($r['status'] !== 200) fail('first use of code', "status {$r['status']}");
// Use it again
$r = http('POST', '/api/v1/device/activate', [], [], null, [], [
    'activation_code' => $actCode2,
    'device_uuid' => sprintf('%08x-%04x-%04x-%04x-%012x',
        random_int(0, 0xffffffff), random_int(0, 0xffff),
        random_int(0, 0xffff), random_int(0, 0xffff),
        random_int(0, 0xffffffffffff)),
    'device_name' => 'Second Target',
]);
if ($r['status'] !== 400) fail('reuse should 400', "status {$r['status']} body=" . $r['body']);
pass('reusing activation code rejected');

/* --- STEP 7: Student revokes device → next call → 401 --- */
echo "\n[7] Student revokes device → next call → 401\n";
$devSrv = new \CTF\Server\Services\DeviceService();
$ok = $devSrv->revokeDevice($deviceId, (int)$stuRow['id']);
if (!$ok) fail('revoke failed');
$r = http('GET', '/api/v1/device/info', [], [], null, [
    'Authorization' => "Bearer {$deviceToken}",
    'X-Device-ID' => $deviceUuid,
]);
if ($r['status'] !== 401) fail('revoked device should 401', "status {$r['status']}");
$devRow = Connection::fetchOne('SELECT status FROM devices WHERE id = :id', [':id' => $deviceId]);
if ($devRow['status'] !== 'revoked') fail('status not revoked', var_export($devRow, true));
pass('revoked device immediately returns 401');

echo "\n=== ALL PASS ===\n";
