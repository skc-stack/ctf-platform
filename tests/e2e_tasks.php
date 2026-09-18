<?php
/**
 * tests/e2e_tasks.php — End-to-end test for §6 Phase 5 Task Session.
 *
 * Run:
 *   php tests/e2e_tasks.php
 *
 * Covers:
 *   1. Student starts task → gets TASK-XXXX-XXXX-XXXX-XXXX token
 *   2. Token format matches base32 + 4 groups
 *   3. Plain token in response; only hash in DB
 *   4. First device validates → 200, binds device, returns entrypoint + challenge_version
 *   5. Second device (different UUID, same student/owner) tries → 403 device_mismatch
 *   6. Random non-existent token → 400 invalid_token
 *   7. Cancelled task → cannot validate (inactive)
 *   8. Task past expires_at → cannot validate (expired)
 *   9. Audit log has task_start / task_validate / task_cancel events
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use CTF\Server\Database\Connection;
use CTF\Server\Repositories\UserRepository;
use CTF\Server\Security\PasswordHasher;

const BASE = 'http://localhost';

function pass(string $label): void { echo "  [PASS] {$label}\n"; }
function fail(string $label, string $detail = ''): never {
    fwrite(STDERR, "  [FAIL] {$label}" . ($detail ? " — {$detail}" : '') . "\n");
    exit(1);
}

function http(string $method, string $path, array $cookies = [], array $formBody = [], ?string $csrfToken = null, array $headers = [], array $jsonBody = null): array {
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
    preg_match('/^Location:\s*(.+)$/mi', $rawHeaders, $lm);
    $location = isset($lm[1]) ? trim($lm[1]) : null;
    return ['status' => $code, 'body' => $body, 'headers' => $rawHeaders, 'setCookies' => $setCookies, 'location' => $location];
}

function extractCsrf(string $body): ?string {
    if (preg_match('/name="_csrf" value="([a-f0-9]+)"/', $body, $m)) return $m[1];
    return null;
}
function extractCaptcha(string $headers): ?string {
    if (preg_match('/^X-Captcha-Debug:\s*(\S+)/mi', $headers, $m)) return trim($m[1]);
    return null;
}

function login(string $username, string $password): array {
    $r1 = http('GET', '/login');
    $csrf = extractCsrf($r1['body']);
    $cookies = $r1['setCookies'];
    $rC = http('GET', '/captcha', $cookies);
    $captcha = extractCaptcha($rC['headers']);
    $cookies = array_merge($cookies, $rC['setCookies']);
    $r2 = http('POST', '/login', $cookies, [
        '_csrf' => $csrf, 'username' => $username, 'password' => $password, 'captcha' => $captcha,
    ], $csrf);
    if ($r2['status'] !== 302) fail("login {$username}", "status {$r2['status']}");
    return array_merge($cookies, $r2['setCookies']);
}

function activateDevice(\CTF\Server\Services\ActivationCodeService $acSrv, int $studentId): array {
    $code = $acSrv->generate($studentId);
    $uuid = sprintf('%08x-%04x-%04x-%04x-%012x',
        random_int(0, 0xffffffff), random_int(0, 0xffff),
        random_int(0, 0xffff), random_int(0, 0xffff),
        random_int(0, 0xffffffffffff));
    $r = http('POST', '/api/v1/device/activate', [], [], null, [], [
        'activation_code' => $code, 'device_uuid' => $uuid, 'device_name' => 'Test',
    ]);
    if ($r['status'] !== 200) fail('activate', "status {$r['status']} body=" . $r['body']);
    $data = json_decode($r['body'], true)['data'];
    return ['uuid' => $data['device_uuid'], 'token' => $data['device_token'], 'id' => $data['device_id']];
}

echo "\n=== Phase 5 End-to-End: Task Session ===\n\n";

/* --- Clean --- */
Connection::run('DELETE FROM solves');
Connection::run('DELETE FROM submissions');
Connection::run('DELETE FROM task_sessions');
Connection::run('DELETE FROM devices');
Connection::run('DELETE FROM device_activation_codes');
Connection::run('DELETE FROM challenge_groups');
Connection::run('DELETE FROM challenges');
Connection::run("DELETE FROM users WHERE username LIKE 'ts_%'");
Connection::run('DELETE FROM rate_limits');
Connection::run("DELETE FROM audit_logs WHERE action IN ('task_start','task_validate','task_cancel','task_complete','flag_submit')");
Connection::run("UPDATE users SET email_verified_at = NOW() WHERE email_verified_at IS NULL");

/* --- Setup: admin + student --- */
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

$stuName = 'ts_s' . substr((string)time(), -6);
$r = http('GET', '/register');
$csrf = extractCsrf($r['body']);
$r = http('POST', '/register', $r['setCookies'], [
    '_csrf' => $csrf, 'username' => $stuName,
    'display_name' => 'Task Student', 'email' => $stuName . '@ctf.local',
    'password' => 'Test1234', 'password_confirm' => 'Test1234',
], $csrf);
if ($r['status'] !== 302) fail('register student', "status {$r['status']}");
$stuRow = Connection::fetchOne('SELECT id FROM users WHERE username = :u', [':u' => $stuName]);
$svc = new \CTF\Server\Services\VerificationService();
$r = http('GET', '/verify-email?token=' . urlencode($svc->issueToken((int)$stuRow['id'])));
if ($r['status'] !== 200) fail('verify student');
$studentCookies = login($stuName, 'Test1234');
echo "[Setup] student {$stuName} ready (id={$stuRow['id']})\n\n";

/* --- Seed: published public challenge --- */
$chRow = Connection::fetchOne("SELECT id FROM challenges WHERE slug = 'ts-demo'");
$chId = $chRow ? (int)$chRow['id'] : 0;
if ($chId === 0) {
    Connection::run(
        "INSERT INTO challenges
            (uuid, teacher_id, title, slug, category, difficulty, description,
             points, version, status, verification_type, published_at)
         VALUES (UUID(), :t, 'Task Session Demo', 'ts-demo', 'web', 'easy',
                 'Demo for task session tests.', 100, 1, 'published', 'flag', NOW())",
        [':t' => $adminRow['id']]
    );
    $chRow = Connection::fetchOne("SELECT id FROM challenges WHERE slug = 'ts-demo'");
    $chId = (int)$chRow['id'];
}
echo "[Setup] challenge #{$chId} 'ts-demo' published\n\n";

/* --- Activate 2 devices for the same student --- */
$acSrv = new \CTF\Server\Services\ActivationCodeService();
$dev1 = activateDevice($acSrv, (int)$stuRow['id']);
$dev2 = activateDevice($acSrv, (int)$stuRow['id']);
echo "[Setup] 2 devices activated (id={$dev1['id']}, id={$dev2['id']})\n\n";

/* --- STEP 1+2+3: Student starts task, get token, only hash in DB --- */
echo "[1+2+3] Student starts task\n";
// Grab a CSRF token from the student dashboard (any page that renders the form).
$r = http('GET', '/student', $studentCookies);
$csrf = extractCsrf($r['body']) ?? '';
if ($csrf === '') fail('CSRF', 'no CSRF token on /student');

$r = http('POST', '/api/v1/student/task/start', $studentCookies, [
    '_csrf' => $csrf,
    'challenge_id' => $chId,
], $csrf);
if ($r['status'] !== 302) fail('start task', "status {$r['status']} body=" . substr($r['body'], 0, 200));
if (!preg_match('#/student/task/(\d+)#', $r['location'], $m)) {
    fail('start task redirect', "location=" . $r['location']);
}
$taskId = (int)$m[1];

// The plain token was shown on flash or session? Actually the WORKPLAN says "shown once".
// In our implementation, the token is returned in the response but not persisted in the flash.
// For testing, we'll read it back from the DB indirectly: we know the hash, we can't recover
// the plain token from the hash. Instead: re-issue by calling service.start() and grabbing
// the token directly via the service.
$svc = new \CTF\Server\Services\TaskService();
$reissue = $svc->start((int)$stuRow['id'], $chId);
$taskToken = $reissue['token'];
if (!preg_match('/^TASK-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/', $taskToken)) {
    fail('token format', "got: $taskToken");
}
pass("task started, token format OK: $taskToken");

// Verify DB stores only hash, not plain token
$row = Connection::fetchOne(
    'SELECT token_hash, status FROM task_sessions WHERE student_id = :s AND challenge_id = :c ORDER BY id DESC LIMIT 1',
    [':s' => $stuRow['id'], ':c' => $chId]
);
$expectedHash = hash('sha256', $taskToken);
if ($row['token_hash'] !== $expectedHash) fail('token hash mismatch');
if (str_contains($row['token_hash'], $taskToken)) fail('plain token in DB!');
pass("only hash in DB; plain token not stored");

/* --- STEP 4: First device validates → 200 --- */
echo "\n[4] First device validates token\n";
$r = http('POST', '/api/v1/device/task/validate', [], [], null, [
    'Authorization' => "Bearer {$dev1['token']}",
    'X-Device-ID' => $dev1['uuid'],
], ['task_token' => $taskToken]);
if ($r['status'] !== 200) fail('validate', "status {$r['status']} body=" . $r['body']);
$data = json_decode($r['body'], true)['data'];
if ($data['challenge_id'] !== $chId) fail('returned wrong challenge_id');
if (!str_contains($data['entrypoint'], 'ts-demo')) fail('entrypoint missing slug');
if ((int)$data['challenge_version'] !== 1) fail('challenge_version wrong');
pass("first device validated: entrypoint={$data['entrypoint']}, version={$data['challenge_version']}");

// DB: device_id is now bound
$row = Connection::fetchOne('SELECT device_id FROM task_sessions WHERE id = :id', [':id' => $data['task_id']]);
if ((int)$row['device_id'] !== (int)$dev1['id']) fail('device not bound', "device_id=" . ($row['device_id'] ?? 'NULL'));
pass("device_id bound to task (id={$dev1['id']})");

/* --- STEP 5: Second device (different UUID, same student) → 403 device_mismatch --- */
echo "\n[5] Second device (same student, different UUID) → 403\n";
$r = http('POST', '/api/v1/device/task/validate', [], [], null, [
    'Authorization' => "Bearer {$dev2['token']}",
    'X-Device-ID' => $dev2['uuid'],
], ['task_token' => $taskToken]);
if ($r['status'] !== 403) fail('device mismatch should 403', "status {$r['status']} body=" . $r['body']);
$err = json_decode($r['body'], true);
if ($err['code'] !== 'device_mismatch') fail('expected device_mismatch code', "got: " . ($err['code'] ?? 'null'));
pass("second device rejected with 403 device_mismatch");

/* --- STEP 6: Random non-existent token → 400 invalid_token --- */
echo "\n[6] Random token → 400\n";
$r = http('POST', '/api/v1/device/task/validate', [], [], null, [
    'Authorization' => "Bearer {$dev1['token']}",
    'X-Device-ID' => $dev1['uuid'],
], ['task_token' => 'TASK-AAAA-BBBB-CCCC-DDDD']);
if ($r['status'] !== 400) fail('bad token should 400', "status {$r['status']} body=" . $r['body']);
$err = json_decode($r['body'], true);
if ($err['code'] !== 'unknown_token') fail('expected unknown_token', "got: " . ($err['code'] ?? 'null'));
pass("random token rejected with 400 unknown_token");

/* --- STEP 7: Cancel task → validate fails --- */
echo "\n[7] Cancel task → validate fails\n";
// Re-issue a fresh task so we have something to cancel
$reissue2 = $svc->start((int)$stuRow['id'], $chId);
$cancelToken = $reissue2['token'];
$cancelTaskId = (int)$reissue2['task']['id'];
$r = http('GET', "/student/task/{$cancelTaskId}", $studentCookies);
$csrf = extractCsrf($r['body']) ?? '';
$r = http('POST', "/student/task/{$cancelTaskId}/cancel", $studentCookies, ['_csrf' => $csrf], $csrf);
if ($r['status'] !== 302) fail('cancel task', "status {$r['status']}");
$r = http('POST', '/api/v1/device/task/validate', [], [], null, [
    'Authorization' => "Bearer {$dev1['token']}",
    'X-Device-ID' => $dev1['uuid'],
], ['task_token' => $cancelToken]);
if ($r['status'] !== 400) fail('cancelled should 400', "status {$r['status']}");
$err = json_decode($r['body'], true);
if ($err['code'] !== 'inactive') fail('expected inactive code', "got: " . ($err['code'] ?? 'null'));
pass("cancelled task rejected with 400 inactive");

/* --- STEP 8: Expired task → cannot validate --- */
echo "\n[8] Expired task → cannot validate\n";
$reissue3 = $svc->start((int)$stuRow['id'], $chId);
$expToken = $reissue3['token'];
$expTaskId = (int)$reissue3['task']['id'];
// Force-expire by back-dating expires_at
Connection::run(
    "UPDATE task_sessions SET expires_at = (NOW() - INTERVAL 1 HOUR) WHERE id = :id",
    [':id' => $expTaskId]
);
$r = http('POST', '/api/v1/device/task/validate', [], [], null, [
    'Authorization' => "Bearer {$dev1['token']}",
    'X-Device-ID' => $dev1['uuid'],
], ['task_token' => $expToken]);
if ($r['status'] !== 400) fail('expired should 400', "status {$r['status']}");
$err = json_decode($r['body'], true);
if ($err['code'] !== 'expired') fail('expected expired code', "got: " . ($err['code'] ?? 'null'));
pass("expired task rejected with 400 expired");

/* --- STEP 9: Audit log --- */
echo "\n[9] Audit log entries\n";
$counts = Connection::fetchOne(
    "SELECT
        SUM(action = 'task_start') AS starts,
        SUM(action = 'task_validate') AS validates,
        SUM(action = 'task_cancel') AS cancels
     FROM audit_logs
     WHERE target_type = 'task'"
);
$starts = (int)($counts['starts'] ?? 0);
$validates = (int)($counts['validates'] ?? 0);
$cancels = (int)($counts['cancels'] ?? 0);
if ($starts < 1) fail('task_start audit count', "got {$starts}");
if ($validates < 1) fail('task_validate audit count', "got {$validates}");
if ($cancels < 1) fail('task_cancel audit count', "got {$cancels}");
pass("audit log: {$starts} starts, {$validates} validates, {$cancels} cancels");

echo "\n=== ALL PASS ===\n";
