<?php
/**
 * tests/e2e_groups.php — End-to-end lifecycle test for groups (§3 / Phase 2).
 *
 * Run:
 *   php tests/e2e_groups.php
 *
 * Covers the 10 steps in WORKPLAN §2.10:
 *   1. Teacher login + create group "CS-101" → get join_code
 *   2. Student A joins by code → sees "CS-101"
 *   3. Student B joins by code → also sees "CS-101"
 *   4. Student A tries wrong code → fails
 *   5. Teacher group detail shows 2 members
 *   6. Teacher removes A → A no longer sees CS-101
 *   7. Student A tries to rejoin → fails (status=banned)
 *   8. Teacher regenerates code → old code no longer works
 *   9. Student B uses new code → can join; Student C with old code → fails
 *  10. Teacher deletes group → student B no longer sees it
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
    $rC = http('GET', '/captcha', $cookies);
    if ($rC['status'] !== 200) fail('GET /captcha', "status {$rC['status']}");
    $captcha = extractCaptcha($rC['headers']);
    if (!$captcha) fail('GET /captcha', 'no X-Captcha-Debug header');
    $cookies = array_merge($cookies, $rC['setCookies']);

    $r2 = http('POST', '/login', $cookies, [
        '_csrf' => $csrf,
        'username' => $username,
        'password' => $password,
        'captcha' => $captcha,
    ], $csrf);
    if ($r2['status'] !== 302) {
        fail("login as {$username}", "status {$r2['status']} body=" . substr($r2['body'], 0, 200));
    }
    return array_merge($cookies, $r2['setCookies']);
}

/**
 * Find the latest flash message by following the redirect (302 → GET).
 * We can't see $_SESSION over HTTP, so we sniff the next-page body for
 * text fragments that would only appear after a flash is shown.
 */
function followRedirect(array $resp, array $cookies, string $path = '/'): array
{
    if ($resp['status'] === 302 && isset($resp['location'])) {
        return http('GET', $resp['location'], array_merge($cookies, $resp['setCookies']));
    }
    return $resp;
}

echo "\n=== Phase 2 End-to-End: Groups ===\n\n";

/* --- Clean prior test data --- */
Connection::run('DELETE FROM group_members');
Connection::run('DELETE FROM `groups`');
Connection::run("DELETE FROM users WHERE username LIKE 'grp_t%' OR username LIKE 'grp_s%'");
Connection::run('DELETE FROM rate_limits');
Connection::run("UPDATE users SET email_verified_at = NOW() WHERE email_verified_at IS NULL");

/* --- Ensure admin exists with known credentials --- */
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
pass('admin login');

/* --- Create teacher (grp_t01), approve, login --- */
echo "\n[Teacher register + approval]\n";
$tname = 'grp_t' . substr((string)time(), -6);
$r = http('GET', '/register');
$csrf = extractCsrf($r['body']);
$r = http('POST', '/register', $r['setCookies'], [
    '_csrf' => $csrf,
    '_role' => 'teacher',
    'username' => $tname,
    'display_name' => 'Groups Teacher',
    'email' => $tname . '@ctf.local',
    'password' => 'Teach1234',
    'password_confirm' => 'Teach1234',
], $csrf);
if ($r['status'] !== 302) fail('register teacher', "status {$r['status']}");
$teachRow = Connection::fetchOne('SELECT id FROM users WHERE username = :u', [':u' => $tname]);
$svc = new \CTF\Server\Services\VerificationService();
$rawToken = $svc->issueToken((int)$teachRow['id']);
$r = http('GET', '/verify-email?token=' . urlencode($rawToken));
if ($r['status'] !== 200) fail('verify teacher email');

// Admin approves
$r = http('GET', '/admin/users', $adminCookies);
$csrf = extractCsrf($r['body']);
preg_match('#/admin/users/(\d+)/approve#', $r['body'], $m);
$tid = $m[1] ?? '';
$r = http('POST', "/admin/users/{$tid}/approve", $adminCookies, ['_csrf' => $csrf], $csrf);
if ($r['status'] !== 302) fail('approve teacher', "status {$r['status']}");
pass("teacher {$tname} created and approved");

$teacherCookies = login($tname, 'Teach1234');
pass('teacher login');

/* --- Create students grp_s01, grp_s02, grp_s03 (active, verified) --- */
echo "\n[Students]\n";
$studentNames = [
    'grp_s' . substr((string)time(), -6) . 'a',
    'grp_s' . substr((string)(time() - 1), -6) . 'b',
    'grp_s' . substr((string)(time() - 2), -6) . 'c',
];
$studentCookies = [];
foreach ($studentNames as $i => $uname) {
    $r = http('GET', '/register');
    $csrf = extractCsrf($r['body']);
    $r = http('POST', '/register', $r['setCookies'], [
        '_csrf' => $csrf,
        'username' => $uname,
        'display_name' => 'Student ' . chr(65 + $i),
        'email' => $uname . '@ctf.local',
        'password' => 'Test1234',
        'password_confirm' => 'Test1234',
    ], $csrf);
    if ($r['status'] !== 302) fail("register {$uname}", "status {$r['status']}");
    $stuRow = Connection::fetchOne('SELECT id FROM users WHERE username = :u', [':u' => $uname]);
    $rawToken = $svc->issueToken((int)$stuRow['id']);
    $r = http('GET', '/verify-email?token=' . urlencode($rawToken));
    if ($r['status'] !== 200) fail("verify {$uname}");
    $studentCookies[$uname] = login($uname, 'Test1234');
}
pass('3 students registered, verified, logged in: ' . implode(', ', $studentNames));

/* --- STEP 1: Teacher creates group "CS-101" --- */
echo "\n[1] Teacher creates group CS-101\n";
$r = http('GET', '/teacher/groups/new', $teacherCookies);
if ($r['status'] !== 200) fail('GET /teacher/groups/new', "status {$r['status']}");
$csrf = extractCsrf($r['body']);
$r = http('POST', '/teacher/groups', $teacherCookies, [
    '_csrf' => $csrf,
    'name' => 'CS-101',
    'description' => '網頁安全入門',
    'max_members' => '',
], $csrf);
if ($r['status'] !== 302 || !str_contains((string)$r['location'], '/teacher/groups/')) {
    fail('POST /teacher/groups', "status {$r['status']} loc=" . ($r['location'] ?? 'none'));
}
$gid = (int)basename((string)$r['location']);
$groupRow = Connection::fetchOne('SELECT * FROM `groups` WHERE id = :id', [':id' => $gid]);
$joinCode = $groupRow['join_code'] ?? null;
if (!$joinCode || !preg_match('/^[A-Z0-9]{8}$/', $joinCode)) {
    fail('create group', "bad join_code: " . var_export($joinCode, true));
}
pass("group CS-101 created (#{$gid}, join_code={$joinCode})");

/* --- STEP 2: Student A joins --- */
echo "\n[2] Student A joins via code\n";
$studentA = $studentNames[0];
$studentB = $studentNames[1];
$studentC = $studentNames[2];
$r = http('GET', '/student/groups/join', $studentCookies[$studentA]);
$csrf = extractCsrf($r['body']);
$r = http('POST', '/student/groups/join', $studentCookies[$studentA], [
    '_csrf' => $csrf,
    'join_code' => $joinCode,
], $csrf);
if ($r['status'] !== 302) fail('A POST join', "status {$r['status']} body=" . substr($r['body'], 0, 200));
$r = http('GET', '/student/groups', $studentCookies[$studentA]);
if ($r['status'] !== 200 || !str_contains($r['body'], 'CS-101')) {
    fail('A GET /student/groups missing CS-101', substr($r['body'], 0, 300));
}
pass("{$studentA} joined and sees CS-101");

/* --- STEP 3: Student B joins --- */
echo "\n[3] Student B joins via code\n";
$r = http('GET', '/student/groups/join', $studentCookies[$studentB]);
$csrf = extractCsrf($r['body']);
$r = http('POST', '/student/groups/join', $studentCookies[$studentB], [
    '_csrf' => $csrf,
    'join_code' => $joinCode,
], $csrf);
if ($r['status'] !== 302) fail('B POST join', "status {$r['status']}");
$r = http('GET', '/student/groups', $studentCookies[$studentB]);
if (!str_contains($r['body'], 'CS-101')) fail('B GET /student/groups missing CS-101');
pass("{$studentB} joined and sees CS-101");

/* --- STEP 4: Student A tries wrong code → fails --- */
echo "\n[4] Student A tries wrong code\n";
$r = http('GET', '/student/groups/join', $studentCookies[$studentA]);
$csrf = extractCsrf($r['body']);
$r = http('POST', '/student/groups/join', $studentCookies[$studentA], [
    '_csrf' => $csrf,
    'join_code' => 'WRONG99',
], $csrf);
if ($r['status'] !== 302) fail('wrong code POST', "status {$r['status']}");
$r = http('GET', '/student/groups', $studentCookies[$studentA]);
// A should still see only CS-101 (table cell only — the JS confirm() also
// contains "CS-101" so we count strong-tag occurrences instead).
if (substr_count($r['body'], '<strong>CS-101</strong>') !== 1) {
    fail('A still sees CS-101 once only', "found " . substr_count($r['body'], '<strong>CS-101</strong>') . ' strong tags');
}
pass("{$studentA} rejected wrong code");

/* --- STEP 5: Teacher sees 2 members in detail --- */
echo "\n[5] Teacher group detail shows 2 members\n";
$r = http('GET', "/teacher/groups/{$gid}", $teacherCookies);
if ($r['status'] !== 200) fail('GET /teacher/groups/{id}', "status {$r['status']}");
if (!str_contains($r['body'], $studentA) || !str_contains($r['body'], $studentB)) {
    fail('teacher group detail missing members', substr($r['body'], 0, 400));
}
if (!str_contains($r['body'], $joinCode)) {
    fail('teacher group detail missing join_code');
}
pass("teacher sees both {$studentA} and {$studentB}");

/* --- STEP 6: Teacher removes A → A no longer sees CS-101 --- */
echo "\n[6] Teacher removes A\n";
$r = http('GET', "/teacher/groups/{$gid}", $teacherCookies);
$csrf = extractCsrf($r['body']);
$stuRowA = Connection::fetchOne('SELECT id FROM users WHERE username = :u', [':u' => $studentA]);
$aid = (int)$stuRowA['id'];
$r = http('POST', "/teacher/groups/{$gid}/remove/{$aid}", $teacherCookies, ['_csrf' => $csrf], $csrf);
if ($r['status'] !== 302) fail('POST remove A', "status {$r['status']}");
$r = http('GET', '/student/groups', $studentCookies[$studentA]);
if (str_contains($r['body'], 'CS-101')) {
    fail('A should NOT see CS-101 after remove', substr($r['body'], 0, 400));
}
pass("{$studentA} no longer sees CS-101 after remove");

/* --- STEP 7: A tries to rejoin → fails (status=banned) --- */
echo "\n[7] A tries to rejoin with same code → banned\n";
$r = http('GET', '/student/groups/join', $studentCookies[$studentA]);
$csrf = extractCsrf($r['body']);
$r = http('POST', '/student/groups/join', $studentCookies[$studentA], [
    '_csrf' => $csrf,
    'join_code' => $joinCode,
], $csrf);
if ($r['status'] !== 302) fail('A rejoin POST', "status {$r['status']}");
$r = http('GET', '/student/groups', $studentCookies[$studentA]);
if (str_contains($r['body'], 'CS-101')) {
    fail('A should NOT see CS-101 after banned', substr($r['body'], 0, 400));
}
$memberRow = Connection::fetchOne(
    'SELECT status FROM group_members WHERE group_id = :g AND student_id = :s',
    [':g' => $gid, ':s' => $aid]
);
if (!$memberRow || $memberRow['status'] !== 'banned') {
    fail('A group_members.status', 'should be banned, got ' . var_export($memberRow, true));
}
pass("{$studentA} blocked; group_members.status=banned");

/* --- STEP 8: Teacher regenerates code → old code no longer works --- */
echo "\n[8] Teacher regenerates join_code\n";
$r = http('GET', "/teacher/groups/{$gid}", $teacherCookies);
$csrf = extractCsrf($r['body']);
$r = http('POST', "/teacher/groups/{$gid}/regenerate-code", $teacherCookies, ['_csrf' => $csrf], $csrf);
if ($r['status'] !== 302) fail('POST regenerate-code', "status {$r['status']}");
$groupRow = Connection::fetchOne('SELECT join_code FROM `groups` WHERE id = :id', [':id' => $gid]);
$newCode = $groupRow['join_code'];
if ($newCode === $joinCode) {
    fail('regenerate code', "old and new codes are identical: {$newCode}");
}
pass("old code {$joinCode} → new code {$newCode}");

// Old code should now fail lookup
$lookup = Connection::fetchOne('SELECT id FROM `groups` WHERE join_code = :c', [':c' => $joinCode]);
if ($lookup) {
    fail('old code lookup', "old code {$joinCode} still resolves to group {$lookup['id']}");
}
pass("old code {$joinCode} no longer resolves to any group");

/* --- STEP 9: B uses new code → can join; C with old code → fails --- */
echo "\n[9] B uses new code; C uses old code\n";
// First, B needs to leave so they can re-join with new code (they're already active)
$r = http('GET', '/student/groups', $studentCookies[$studentB]);
$csrf = extractCsrf($r['body']);
$r = http('POST', '/student/groups/' . $gid . '/leave', $studentCookies[$studentB], ['_csrf' => $csrf], $csrf);
if ($r['status'] !== 302) fail('B leave', "status {$r['status']}");
$r = http('GET', '/student/groups', $studentCookies[$studentB]);
if (str_contains($r['body'], 'CS-101')) fail('B should not see CS-101 after leave');
pass("{$studentB} left CS-101 (so they can rejoin with new code)");

// B joins with new code
$r = http('GET', '/student/groups/join', $studentCookies[$studentB]);
$csrf = extractCsrf($r['body']);
$r = http('POST', '/student/groups/join', $studentCookies[$studentB], [
    '_csrf' => $csrf,
    'join_code' => $newCode,
], $csrf);
if ($r['status'] !== 302) fail('B join with new code', "status {$r['status']}");
$r = http('GET', '/student/groups', $studentCookies[$studentB]);
if (!str_contains($r['body'], 'CS-101')) fail('B should see CS-101 after rejoining with new code');
pass("{$studentB} rejoined with new code {$newCode}");

// C tries with old code → fails
$r = http('GET', '/student/groups/join', $studentCookies[$studentC]);
$csrf = extractCsrf($r['body']);
$r = http('POST', '/student/groups/join', $studentCookies[$studentC], [
    '_csrf' => $csrf,
    'join_code' => $joinCode,
], $csrf);
if ($r['status'] !== 302) fail('C old code POST', "status {$r['status']}");
$r = http('GET', '/student/groups', $studentCookies[$studentC]);
if (str_contains($r['body'], 'CS-101')) {
    fail('C should NOT see CS-101 via old code', substr($r['body'], 0, 400));
}
pass("{$studentC} rejected using old code");

/* --- STEP 10: Teacher deletes group → B no longer sees CS-101 --- */
echo "\n[10] Teacher deletes group\n";
$r = http('GET', "/teacher/groups/{$gid}", $teacherCookies);
$csrf = extractCsrf($r['body']);
$r = http('POST', "/teacher/groups/{$gid}/delete", $teacherCookies, ['_csrf' => $csrf], $csrf);
if ($r['status'] !== 302) fail('POST delete', "status {$r['status']}");
$r = http('GET', '/teacher/groups', $teacherCookies);
if (str_contains($r['body'], 'CS-101')) {
    fail('teacher should not see CS-101 in list after delete', substr($r['body'], 0, 400));
}
$r = http('GET', '/student/groups', $studentCookies[$studentB]);
if (str_contains($r['body'], 'CS-101')) {
    fail('B should NOT see CS-101 after delete', substr($r['body'], 0, 400));
}
$row = Connection::fetchOne('SELECT id FROM `groups` WHERE id = :id', [':id' => $gid]);
if ($row) fail('group row should be deleted');
$membersLeft = (int)Connection::fetchOne('SELECT COUNT(*) AS c FROM group_members WHERE group_id = :g', [':g' => $gid])['c'];
if ($membersLeft !== 0) fail('group_members should cascade to 0', "got {$membersLeft}");
pass("group deleted; teacher list clean; {$studentB} list clean; CASCADE removed members");

echo "\n=== ALL PASS ===\n";
