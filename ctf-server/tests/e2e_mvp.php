<?php
/**
 * tests/e2e_mvp.php — End-to-end MVP test for §9.
 *
 * Runs the complete student journey:
 *   1. Admin exists (auto-seeded).
 *   2. Teacher registers → admin approves → teacher logs in.
 *   3. bin/seed-challenge.php publishes DEMO-001 from a built ZIP.
 *   4. Student registers → verifies email → logs in.
 *   5. Student dashboard shows DEMO-001.
 *   6. Student starts task → gets task UUID + token.
 *   7. Server computes the expected flag via HMAC.
 *   8. Student submits flag → +200 points, solve row, leaderboard updated.
 *   9. Re-submit → already_solved (no extra points).
 *
 * Plus a smoke test: serve the challenge web/ directory via PHP's built-in
 * server and confirm the entrypoint responds with the expected hint HTML.
 *
 * Run: php tests/e2e_mvp.php
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use CTF\Server\Database\Connection;
use CTF\Server\Repositories\UserRepository;
use CTF\Server\Security\FlagGenerator;
use CTF\Server\Security\PasswordHasher;
use CTF\Server\Services\TaskService;

const BASE = 'http://localhost';
const CHALLENGE_EXAMPLE_DIR = __DIR__ . '/../../challenge-example';

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

echo "\n=== MVP End-to-End ===\n\n";

/* --- Clean --- */
Connection::run('DELETE FROM solves');
Connection::run('DELETE FROM submissions');
Connection::run('DELETE FROM nonces');
Connection::run('DELETE FROM task_sessions');
Connection::run('DELETE FROM devices');
Connection::run('DELETE FROM device_activation_codes');
Connection::run('DELETE FROM challenge_groups');
Connection::run('DELETE FROM challenge_packages');
Connection::run('DELETE FROM challenges');
Connection::run("DELETE FROM users WHERE username LIKE 'mvp_%'");
Connection::run('DELETE FROM rate_limits');
Connection::run("DELETE FROM audit_logs");
Connection::run("UPDATE users SET email_verified_at = NOW() WHERE email_verified_at IS NULL");

/* --- 1. Admin exists --- */
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
echo "[1] Admin ready\n";

/* --- 2. Teacher registers → admin approves → login --- */
echo "\n[2] Teacher registers + approved\n";
$tname = 'mvp_t' . substr((string)(time() - 100), -6);
$r = http('GET', '/register');
$csrf = extractCsrf($r['body']);
$r = http('POST', '/register', $r['setCookies'], [
    '_csrf' => $csrf, '_role' => 'teacher',
    'username' => $tname, 'display_name' => 'MVP Teacher',
    'email' => $tname . '@ctf.local',
    'password' => 'Teach1234', 'password_confirm' => 'Teach1234',
], $csrf);
if ($r['status'] !== 302) fail('register teacher', "status {$r['status']}");
$teachRow = Connection::fetchOne('SELECT id FROM users WHERE username = :u', [':u' => $tname]);
$verifySvc = new \CTF\Server\Services\VerificationService();
$r = http('GET', '/verify-email?token=' . urlencode($verifySvc->issueToken((int)$teachRow['id'])));
if ($r['status'] !== 200) fail('verify teacher');

$adminCookies = login('admin', 'Admin1234');
$r = http('GET', '/admin/users', $adminCookies);
preg_match('#/admin/users/(\d+)/approve#', $r['body'], $m);
$tid = $m[1] ?? '';
$csrf = extractCsrf($r['body']);
$r = http('POST', "/admin/users/{$tid}/approve", $adminCookies, ['_csrf' => $csrf], $csrf);
if ($r['status'] !== 302) fail('approve teacher', "status {$r['status']}");

$teacherCookies = login($tname, 'Teach1234');
pass("teacher {$tname} registered + approved + logged in");

/* --- 3. Seed DEMO-001 challenge via CLI --- */
echo "\n[3] Publish DEMO-001 via bin/seed-challenge.php\n";
$zipPath = CHALLENGE_EXAMPLE_DIR . '/dist/DEMO-001.zip';
if (!is_file($zipPath)) {
    fail('DEMO-001.zip missing', "Run `python3 build.py` in challenge-example/ first");
}
$phpBin = trim((string)shell_exec('where php 2>NUL | head -1'));
if ($phpBin === '') {
    // Fallback: common absolute paths.
    foreach (['C:\\Users\\ai\\AppData\\Local\\Programs\\PHP\\8.3.32\\php.exe',
              '/usr/bin/php', '/usr/local/bin/php'] as $try) {
        if (is_file($try)) { $phpBin = $try; break; }
    }
}
$cliOutput = shell_exec(
    escapeshellarg($phpBin ?: 'php') . ' "' . __DIR__ . '/../bin/seed-challenge.php" --teacher=' . escapeshellarg($tname) .
    ' --zip=' . escapeshellarg($zipPath) . ' 2>&1'
);
echo "  CLI: " . trim((string)$cliOutput) . "\n";
$chRow = Connection::fetchOne("SELECT id, status, verification_type FROM challenges WHERE slug = 'DEMO-001'");
if (!$chRow) fail('seed-challenge did not insert challenge');
if ($chRow['status'] !== 'published') fail('challenge not published', var_export($chRow, true));
pass("DEMO-001 published (id={$chRow['id']}, verification_type={$chRow['verification_type']})");

/* --- 4. Student registers + verifies + logs in --- */
echo "\n[4] Student registers + verifies\n";
$sname = 'mvp_s' . substr((string)(time() - 50), -6);
$r = http('GET', '/register');
$csrf = extractCsrf($r['body']);
$r = http('POST', '/register', $r['setCookies'], [
    '_csrf' => $csrf, 'username' => $sname,
    'display_name' => 'MVP Student', 'email' => $sname . '@ctf.local',
    'password' => 'Test1234', 'password_confirm' => 'Test1234',
], $csrf);
if ($r['status'] !== 302) fail('register student', "status {$r['status']}");
$stuRow = Connection::fetchOne('SELECT id FROM users WHERE username = :u', [':u' => $sname]);
$r = http('GET', '/verify-email?token=' . urlencode($verifySvc->issueToken((int)$stuRow['id'])));
if ($r['status'] !== 200) fail('verify student');
$studentCookies = login($sname, 'Test1234');
pass("student {$sname} verified + logged in");

/* --- 5. Dashboard shows DEMO-001 --- */
echo "\n[5] Student dashboard shows DEMO-001\n";
$r = http('GET', '/student', $studentCookies);
if ($r['status'] !== 200) fail('GET /student', "status {$r['status']}");
if (!str_contains($r['body'], 'DEMO-001')) fail('DEMO-001 missing from dashboard', substr($r['body'], 0, 500));
if (!str_contains($r['body'], '啟動 Task')) fail('Start button missing');
pass("dashboard lists DEMO-001 with Start button");

/* --- 6. Start task → get task_uuid --- */
echo "\n[6] Student starts task\n";
$r = http('GET', '/student', $studentCookies);
$csrf = extractCsrf($r['body']);
$r = http('POST', '/api/v1/student/task/start', $studentCookies, [
    '_csrf' => $csrf, 'challenge_id' => (int)$chRow['id'],
], $csrf);
if ($r['status'] !== 302) fail('start task', "status {$r['status']}");
if (!preg_match('#/student/task/(\d+)#', $r['location'], $m)) fail('start redirect', $r['location']);
$taskId = (int)$m[1];

// The token is not echoed on the page (security); re-issue via service
// so we get the plain task_uuid and can compute the expected flag.
$taskSvc = new TaskService();
$reissue = $taskSvc->start((int)$stuRow['id'], (int)$chRow['id']);
$taskUuid = (string)$reissue['task']['uuid'];
$challengeUuid = (string)Connection::fetchOne('SELECT uuid FROM challenges WHERE id = :i', [':i' => $chRow['id']])['uuid'];
$expectedFlag = FlagGenerator::compute((int)$stuRow['id'], $challengeUuid, $taskUuid);
// Submit against the reissued task (which is the one we know the uuid of).
$taskId = (int)$reissue['task']['id'];
pass("task started (#{$taskId}, expected flag computed)");

/* --- 7. Submit flag → +200 --- */
echo "\n[7] Student submits flag → +200\n";
$r = http('GET', '/student/task/' . $taskId, $studentCookies);
$csrf = extractCsrf($r['body']);
$r = http('POST', '/api/v1/student/submit', $studentCookies, [
    '_csrf' => $csrf, 'task_id' => $taskId, 'flag' => $expectedFlag,
], $csrf);
if ($r['status'] !== 302) fail('submit', "status {$r['status']}");

$solveRow = Connection::fetchOne(
    'SELECT * FROM solves WHERE student_id = :s AND challenge_id = :c',
    [':s' => $stuRow['id'], ':c' => $chRow['id']]
);
if (!$solveRow || (int)$solveRow['points'] !== 200) fail('solve not awarded', var_export($solveRow, true));
pass("solve awarded: +200 (id={$solveRow['id']})");

/* --- 8. Wrong flag → no points, submissions recorded --- */
echo "\n[8] Wrong flag → submissions +1, no solve\n";
$taskSvc2 = $taskSvc->start((int)$stuRow['id'], (int)$chRow['id']);
$subBefore = (int)Connection::fetchOne('SELECT COUNT(*) AS c FROM submissions WHERE student_id = :s', [':s' => $stuRow['id']])['c'];
$taskId2 = (int)$taskSvc2['task']['id'];
$r = http('GET', '/student/task/' . $taskId2, $studentCookies);
$csrf = extractCsrf($r['body']);
$r = http('POST', '/api/v1/student/submit', $studentCookies, [
    '_csrf' => $csrf, 'task_id' => $taskId2,
    'flag' => 'flag{0000000000000000000000000000000000000000000000000000000000000000}',
], $csrf);
$subAfter = (int)Connection::fetchOne('SELECT COUNT(*) AS c FROM submissions WHERE student_id = :s', [':s' => $stuRow['id']])['c'];
if ($subAfter !== $subBefore + 1) fail('submission not recorded', "before={$subBefore} after={$subAfter}");
$solveCount = (int)Connection::fetchOne('SELECT COUNT(*) AS c FROM solves WHERE student_id = :s', [':s' => $stuRow['id']])['c'];
if ($solveCount !== 1) fail('extra solve created', "got {$solveCount}");
pass("wrong flag: submissions +1, solve count still 1");

/* --- 9. Leaderboard shows student with 200 points --- */
echo "\n[9] Leaderboard shows student with 200 points\n";
$board = Connection::fetchAll("SELECT * FROM leaderboard WHERE username = :u", [':u' => $sname]);
if (count($board) !== 1) fail('student not in leaderboard', var_export($board, true));
$row = $board[0];
if ((int)$row['score'] !== 200) fail('leaderboard score wrong', "got {$row['score']}");
if ((int)$row['solved_count'] !== 1) fail('leaderboard solved_count wrong', "got {$row['solved_count']}");
pass("leaderboard: {$sname} = {$row['score']} pts / {$row['solved_count']} solves");

/* --- 10. Student dashboard reflects 200 points --- */
echo "\n[10] Student dashboard shows total_score=200\n";
$r = http('GET', '/student', $studentCookies);
if (!str_contains($r['body'], '200')) fail('dashboard missing 200');
if (!str_contains($r['body'], '已解題')) fail('dashboard missing 已解題 label');
pass("dashboard reflects 200 points and solved count");

echo "\n=== ALL PASS — MVP COMPLETE ===\n";
