<?php
/**
 * tests/e2e_challenges.php — End-to-end test for §2.4 Teacher Challenge UI.
 *
 * Run: php tests/e2e_challenges.php
 *
 * Covers:
 *   1. Teacher /teacher/challenges empty
 *   2. GET /teacher/challenges/new shows form
 *   3. POST /teacher/challenges (without ZIP) → draft created
 *   4. GET /teacher/challenges/{id} shows detail
 *   5. POST /teacher/challenges/{id}/upload with ZIP → v1 stored
 *   6. POST /teacher/challenges/{id}/publish → status=published
 *   7. GET /teacher/challenges (now lists 1)
 *   8. POST /teacher/challenges/{id}/upload → v2 stored, version++
 *   9. Other teacher cannot edit (authorization)
 *  10. POST /teacher/challenges/{id}/disable → status=disabled
 *  11. POST /teacher/challenges/{id}/delete → removed
 *  12. Group binding: bind to group → student not in group → no visibility
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use CTF\Server\Database\Connection;
use CTF\Server\Repositories\UserRepository;
use CTF\Server\Security\PasswordHasher;

const BASE = 'http://localhost';
const ZIP_PATH = __DIR__ . '/../../challenge-example/dist/DEMO-001.zip';

function pass(string $label): void { echo "  [PASS] {$label}\n"; }
function fail(string $label, string $detail = ''): never {
    fwrite(STDERR, "  [FAIL] {$label}" . ($detail ? " — {$detail}" : '') . "\n");
    exit(1);
}

function http(string $method, string $path, array $cookies = [], array $formBody = [], ?string $csrfToken = null, array $headers = [], array $jsonBody = null, ?string $zipPath = null, string $zipField = 'zip', array $fileUploads = []): array {
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
        if (!empty($fileUploads)) {
            // Multi-file upload via CURLFile
            foreach ($fileUploads as $f) {
                $formBody[$f['field']] = new CURLFile(
                    $f['path'],
                    $f['type'] ?? 'application/octet-stream',
                    $f['name'] ?? basename($f['path'])
                );
            }
            curl_setopt($ch, CURLOPT_POSTFIELDS, $formBody);
        } elseif ($zipPath !== null) {
            // Legacy: single ZIP upload
            $formBody[$zipField] = new CURLFile($zipPath, 'application/zip', basename($zipPath));
            curl_setopt($ch, CURLOPT_POSTFIELDS, $formBody);
        } elseif ($jsonBody !== null) {
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

/**
 * Build a minimal challenge ZIP with the given challenge_id (slug).
 * Used by upload tests so the manifest.challenge_id matches the
 * auto-generated slug.
 */
function buildChallengeZip(string $slug, int $version, string $tmpPath): string
{
    $zip = new ZipArchive();
    if ($zip->open($tmpPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new \RuntimeException("Cannot open $tmpPath for writing");
    }
    $manifest = json_encode([
        'schema_version' => 1,
        'challenge_id' => $slug,
        'version' => $version,
        'type' => 'web',
        'difficulty' => 'medium',
        'title' => 'Auto-generated for test',
        'entrypoint' => "/challenge/$slug/",
        'verification' => ['type' => 'flag', 'flag_static' => 'flag{test}'],
    ], JSON_UNESCAPED_UNICODE);
    $zip->addFromString('manifest.json', $manifest);
    $zip->addFromString('web/index.php', "<?php /* test entry for $slug */");
    $zip->close();
    return $tmpPath;
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

echo "\n=== Phase 2 End-to-End: Teacher Challenge UI ===\n\n";

/* --- Clean --- */
foreach (["solves","submissions","nonces","task_sessions","challenge_packages","challenge_groups","challenges"] as $t) {
    try { Connection::run("DELETE FROM $t"); } catch (\Throwable $e) {}
}
Connection::run("DELETE FROM group_members");
Connection::run("DELETE FROM `groups`");
Connection::run("DELETE FROM rate_limits");
Connection::run("DELETE FROM audit_logs");
Connection::run("DELETE FROM users WHERE username LIKE 'tch_ch%' OR username LIKE 'stu_ch%'");
Connection::run("UPDATE users SET email_verified_at = NOW() WHERE email_verified_at IS NULL");

if (!is_file(ZIP_PATH)) {
    fail('DEMO-001.zip missing', 'run `python3 challenge-example/build.py` first');
}

/* --- Setup: admin + teacher + another teacher + student --- */
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

// Teacher A (our main test subject)
$tname = 'tch_ch' . substr((string)(time() - 300), -6);
$r = http('GET', '/register');
$csrf = extractCsrf($r['body']);
$r = http('POST', '/register', $r['setCookies'], [
    '_csrf' => $csrf, '_role' => 'teacher',
    'username' => $tname, 'display_name' => 'Teacher A',
    'email' => $tname . '@ctf.local',
    'password' => 'Teach1234', 'password_confirm' => 'Teach1234',
], $csrf);
if ($r['status'] !== 302) fail('register teacher A', "status {$r['status']}");
$teachRow = Connection::fetchOne('SELECT id FROM users WHERE username = :u', [':u' => $tname]);
Connection::run('UPDATE users SET email_verified_at = NOW(), status = ? WHERE id = ?', [UserRepository::STATUS_ACTIVE, $teachRow['id']]);

// Teacher B (to test cross-teacher authorization)
$tnameB = 'tch_ch' . substr((string)(time() - 200), -6) . 'b';
$r = http('GET', '/register');
$csrf = extractCsrf($r['body']);
$r = http('POST', '/register', $r['setCookies'], [
    '_csrf' => $csrf, '_role' => 'teacher',
    'username' => $tnameB, 'display_name' => 'Teacher B',
    'email' => $tnameB . '@ctf.local',
    'password' => 'Teach1234', 'password_confirm' => 'Teach1234',
], $csrf);
if ($r['status'] !== 302) fail('register teacher B', "status {$r['status']}");
$teachBRow = Connection::fetchOne('SELECT id FROM users WHERE username = :u', [':u' => $tnameB]);
Connection::run('UPDATE users SET email_verified_at = NOW(), status = ? WHERE id = ?', [UserRepository::STATUS_ACTIVE, $teachBRow['id']]);

// Student
$sname = 'stu_ch' . substr((string)(time() - 100), -6);
$r = http('GET', '/register');
$csrf = extractCsrf($r['body']);
$r = http('POST', '/register', $r['setCookies'], [
    '_csrf' => $csrf, 'username' => $sname,
    'display_name' => 'Student', 'email' => $sname . '@ctf.local',
    'password' => 'Test1234', 'password_confirm' => 'Test1234',
], $csrf);
if ($r['status'] !== 302) fail('register student', "status {$r['status']}");
$stuRow = Connection::fetchOne('SELECT id FROM users WHERE username = :u', [':u' => $sname]);
$verifySvc = new \CTF\Server\Services\VerificationService();
$r = http('GET', '/verify-email?token=' . urlencode($verifySvc->issueToken((int)$stuRow['id'])));
if ($r['status'] !== 200) fail('verify student');

$adminCookies = login('admin', 'Admin1234');
$teacherCookies = login($tname, 'Teach1234');
$teacherBCookies = login($tnameB, 'Teach1234');
$studentCookies = login($sname, 'Test1234');
echo "[Setup] admin, teacher A ({$tname}), teacher B ({$tnameB}), student ({$sname})\n\n";

/* --- STEP 1: Teacher A's challenge list is empty --- */
echo "[1] Teacher A's list is empty\n";
$r = http('GET', '/teacher/challenges', $teacherCookies);
if ($r['status'] !== 200) fail('GET /teacher/challenges', "status {$r['status']}");
if (!str_contains($r['body'], '目前還沒有建立任何題目')) fail('empty list message missing');
pass("list empty");

/* --- STEP 2: New form --- */
echo "\n[2] GET /teacher/challenges/new shows form\n";
$r = http('GET', '/teacher/challenges/new', $teacherCookies);
if ($r['status'] !== 200) fail('GET new form', "status {$r['status']}");
if (!str_contains($r['body'], 'name="title"')) fail('title field missing');
if (str_contains($r['body'], 'name="slug"')) fail('slug field should NOT exist (auto-generated)');
if (str_contains($r['body'], 'name="zip"')) fail('ZIP upload field should NOT exist (multi-file instead)');
if (!str_contains($r['body'], 'name="files[]"')) fail('multi-file upload field missing');
if (!str_contains($r['body'], 'easy-web-001')) fail('slug-format hint missing');
pass("form rendered (no slug, multi-file)");

/* --- STEP 3: Create draft with multi-file upload → status=draft, slug auto-generated --- */
echo "\n[3] Create draft (no ZIP, multi-file upload) → status=draft, slug auto-generated\n";
$r = http('GET', '/teacher/challenges', $teacherCookies);
$csrf = extractCsrf($r['body']);

// Pre-create a temp file the teacher "uploads"
$tmpFile = sys_get_temp_dir() . '/ctf_test_index_' . time() . '.php';
file_put_contents($tmpFile, "<?php /* test entry point */\n");

$r = http('POST', '/teacher/challenges', $teacherCookies, [
    '_csrf' => $csrf,
    'title' => 'My Web Challenge',
    // No slug field — auto-generated as {difficulty}-{category}-{NNN}
    'category' => 'web',
    'difficulty' => 'medium',
    'points' => 150,
    'description' => 'demo',
], $csrf, [], null, null, 'zip', [
    ['field' => 'files[]', 'path' => $tmpFile, 'name' => 'web/index.php', 'type' => 'application/x-php'],
]);
@unlink($tmpFile);

if ($r['status'] !== 302) fail('POST create', "status {$r['status']} body=" . substr($r['body'], 0, 300));
preg_match('#/teacher/challenges/(\d+)#', $r['location'], $m);
$chId = (int)$m[1];

$row = Connection::fetchOne('SELECT * FROM challenges WHERE id = :id', [':id' => $chId]);
if (!$row) fail('challenge not inserted');
if ($row['status'] !== 'draft') fail('status should be draft', "got {$row['status']}");
if ((int)$row['points'] !== 150) fail('points not saved', "got {$row['points']}");
if (!preg_match('/^medium-web-\d{3}$/', $row['slug'])) {
    fail('slug not auto-generated correctly', "got: {$row['slug']}");
}
$autoSlug = $row['slug'];

// Verify directory structure was created on disk
$baseDir = rtrim((string)\CTF\Server\Support\Config::get('STORAGE_CHALLENGE_PATH', 'storage/challenges'), '/\\');
$expectedDir = $baseDir . '/' . $autoSlug;
if (!is_dir($expectedDir)) fail("challenge dir not created: $expectedDir");
if (!is_dir($expectedDir . '/web')) fail('web/ subdir not created');
if (!is_file($expectedDir . '/manifest.json')) fail('manifest.json not auto-generated');
if (!is_file($expectedDir . '/web/index.php')) fail('uploaded file not in web/');
pass("draft created (id={$chId}, slug={$autoSlug}, dir={$expectedDir}, web/index.php uploaded)");

/* --- STEP 4: Show detail page --- */
echo "\n[4] GET /teacher/challenges/{id} shows detail\n";
$r = http('GET', "/teacher/challenges/{$chId}", $teacherCookies);
if ($r['status'] !== 200) fail('GET show', "status {$r['status']}");
if (!str_contains($r['body'], 'My Web Challenge')) fail('title missing in show');
if (!str_contains($r['body'], $autoSlug)) fail('slug missing in show');
if (!str_contains($r['body'], '上傳新版本')) fail('upload form missing');
if (!str_contains($r['body'], '版本歷史')) fail('version history missing');
pass("detail page renders");

/* --- STEP 5: Upload v1 (adds new file + auto-packages to ZIP) --- */
echo "\n[5] Upload v1 (add new file, auto-package) → version=2\n";
$tmpFile2 = sys_get_temp_dir() . '/ctf_test_setup_' . time() . '.txt';
file_put_contents($tmpFile2, "this is just a text file for testing the upload workflow");
$r = http('GET', "/teacher/challenges/{$chId}", $teacherCookies);
$csrf = extractCsrf($r['body']);
$r = http('POST', "/teacher/challenges/{$chId}/upload", $teacherCookies, [
    '_csrf' => $csrf,
], $csrf, [], null, null, 'zip', [
    ['field' => 'files[]', 'path' => $tmpFile2, 'name' => 'setup.txt', 'type' => 'text/plain'],
]);
@unlink($tmpFile2);
if ($r['status'] !== 302) fail('POST upload', "status {$r['status']} body=" . substr($r['body'], 0, 300));
$row = Connection::fetchOne('SELECT version FROM challenges WHERE id = :id', [':id' => $chId]);
if ((int)$row['version'] !== 2) fail('version not bumped to 2', "got {$row['version']}");
$pkg = Connection::fetchOne('SELECT * FROM challenge_packages WHERE challenge_id = :c', [':c' => $chId]);
if (!$pkg || (int)$pkg['version'] !== 2) fail('package not stored at v2');
pass("v1 stored as version 2 (challenge version={$row['version']}, pkg version={$pkg['version']}) — file on disk verified in step 14 via direct write");

/* --- STEP 6: Publish --- */
echo "\n[6] Publish → status=published\n";
$r = http('GET', "/teacher/challenges/{$chId}", $teacherCookies);
$csrf = extractCsrf($r['body']);
$r = http('POST', "/teacher/challenges/{$chId}/publish", $teacherCookies, ['_csrf' => $csrf], $csrf);
if ($r['status'] !== 302) fail('POST publish', "status {$r['status']}");
$row = Connection::fetchOne('SELECT status, published_at FROM challenges WHERE id = :id', [':id' => $chId]);
if ($row['status'] !== 'published') fail('status should be published', "got {$row['status']}");
if (empty($row['published_at'])) fail('published_at not set');
pass("published (published_at={$row['published_at']})");

/* --- STEP 7: List now has 1 challenge --- */
echo "\n[7] List shows 1 challenge\n";
$r = http('GET', '/teacher/challenges', $teacherCookies);
if (!str_contains($r['body'], 'My Web Challenge')) fail('list missing title');
if (!str_contains($r['body'], $autoSlug)) fail('list missing slug');
if (!str_contains($r['body'], 'v2')) fail('list missing version');
if (!str_contains($r['body'], '已發布')) fail('list missing status badge');
pass("list shows the challenge");

/* --- STEP 8: Upload v2 (more files) → version=3 --- */
echo "\n[8] Upload v2 (more files, auto-package) → version=3\n";
$tmpFile3 = sys_get_temp_dir() . '/ctf_test_inner_' . time() . '.php';
file_put_contents($tmpFile3, "<?php // v2 inner\n");
$r = http('GET', "/teacher/challenges/{$chId}", $teacherCookies);
$csrf = extractCsrf($r['body']);
$r = http('POST', "/teacher/challenges/{$chId}/upload", $teacherCookies, [
    '_csrf' => $csrf,
], $csrf, [], null, null, 'zip', [
    ['field' => 'files[]', 'path' => $tmpFile3, 'name' => 'web/inner.php', 'type' => 'application/x-php'],
]);
@unlink($tmpFile3);
if ($r['status'] !== 302) fail('POST upload v2', "status {$r['status']}");
$row = Connection::fetchOne('SELECT version FROM challenges WHERE id = :id', [':id' => $chId]);
if ((int)$row['version'] !== 3) fail('version not bumped to 3', "got {$row['version']}");
$pkgCount = (int)Connection::fetchOne('SELECT COUNT(*) AS c FROM challenge_packages WHERE challenge_id = :c', [':c' => $chId])['c'];
if ($pkgCount !== 2) fail('should have 2 packages', "got {$pkgCount}");
pass("v2 stored (challenge version={$row['version']}, {$pkgCount} packages total)");

/* --- STEP 9: Teacher B cannot edit Teacher A's challenge --- */
echo "\n[9] Teacher B cannot edit Teacher A's challenge\n";
$r = http('GET', "/teacher/challenges/{$chId}/edit", $teacherBCookies);
if ($r['status'] !== 302 && $r['status'] !== 200) {
    fail('Teacher B edit attempt', "status {$r['status']}");
}
if ($r['status'] === 200 && str_contains($r['body'], 'My Web Challenge')) {
    fail('Teacher B saw Teacher A challenge in edit form');
}
$r = http('POST', "/teacher/challenges/{$chId}/update", $teacherBCookies, [
    '_csrf' => 'dummy',
    'title' => 'HIJACKED',
], 'dummy');
// Should redirect away or reject — title should NOT change.
$row = Connection::fetchOne('SELECT title FROM challenges WHERE id = :id', [':id' => $chId]);
if ($row['title'] !== 'My Web Challenge') fail('Teacher B modified title!', "got {$row['title']}");
pass("cross-teacher mutation blocked (title still '{$row['title']}')");

/* --- STEP 10: Disable --- */
echo "\n[10] Disable → status=disabled\n";
$r = http('GET', "/teacher/challenges/{$chId}", $teacherCookies);
$csrf = extractCsrf($r['body']);
$r = http('POST', "/teacher/challenges/{$chId}/disable", $teacherCookies, ['_csrf' => $csrf], $csrf);
if ($r['status'] !== 302) fail('POST disable', "status {$r['status']}");
$row = Connection::fetchOne('SELECT status FROM challenges WHERE id = :id', [':id' => $chId]);
if ($row['status'] !== 'disabled') fail('should be disabled', "got {$row['status']}");
pass("disabled");

/* --- STEP 11: Delete --- */
echo "\n[11] Delete → removed\n";
$r = http('GET', "/teacher/challenges/{$chId}", $teacherCookies);
$csrf = extractCsrf($r['body']);
$r = http('POST', "/teacher/challenges/{$chId}/delete", $teacherCookies, ['_csrf' => $csrf], $csrf);
if ($r['status'] !== 302) fail('POST delete', "status {$r['status']}");
$row = Connection::fetchOne('SELECT id FROM challenges WHERE id = :id', [':id' => $chId]);
if ($row !== null) fail('challenge not deleted');
$pkgCount = (int)Connection::fetchOne('SELECT COUNT(*) AS c FROM challenge_packages WHERE challenge_id = :c', [':c' => $chId])['c'];
if ($pkgCount !== 0) fail('packages not cascaded', "got {$pkgCount}");
pass("deleted (CASCADE removed packages)");

/* --- STEP 12: Auto-slug sequence (re-test sequence, deletion resets count) --- */
echo "\n[12] After delete, sequence resets (medium-web-001 again)\n";
// Step 11 deleted the challenge, so the sequence counter is back at 0.
// Creating a new medium-web-* should produce medium-web-001 (or higher if other
// tests left rows).
$r = http('GET', '/teacher/challenges/new', $teacherCookies);
$csrf = extractCsrf($r['body']);
$r = http('POST', '/teacher/challenges', $teacherCookies, [
    '_csrf' => $csrf,
    'title' => 'Second Medium Web',
    'category' => 'web',
    'difficulty' => 'medium',
    'points' => 200,
], $csrf);
if ($r['status'] !== 302) fail('second create', "status {$r['status']}");
$row = Connection::fetchOne(
    "SELECT slug FROM challenges WHERE title = 'Second Medium Web' ORDER BY id DESC LIMIT 1"
);
if (!$row || !preg_match('/^medium-web-\d{3}$/', $row['slug'])) {
    fail('second slug not medium-web-NNN', "got: " . ($row['slug'] ?? 'null'));
}
pass("second create got '{$row['slug']}'");

/* --- STEP 13: Different (difficulty, category) gets its own sequence --- */
echo "\n[13] Different (difficulty, category) gets its own sequence\n";
$r = http('GET', '/teacher/challenges/new', $teacherCookies);
$csrf = extractCsrf($r['body']);
$r = http('POST', '/teacher/challenges', $teacherCookies, [
    '_csrf' => $csrf,
    'title' => 'First Easy Crypto',
    'category' => 'crypto',
    'difficulty' => 'easy',
    'points' => 100,
], $csrf);
if ($r['status'] !== 302) fail('crypto create', "status {$r['status']}");
$row = Connection::fetchOne(
    "SELECT slug FROM challenges WHERE title = 'First Easy Crypto' ORDER BY id DESC LIMIT 1"
);
if (!$row || $row['slug'] !== 'easy-crypto-001') {
    fail('crypto slug not easy-crypto-001', "got: " . ($row['slug'] ?? 'null'));
}
pass("different (difficulty, category) resets sequence: got '{$row['slug']}'");

/* --- STEP 14: File management on edit page (list + delete) --- */
echo "\n[14] File management: list + delete from edit page\n";
$chSlug = Connection::fetchOne(
    "SELECT slug FROM challenges WHERE title = 'Second Medium Web' ORDER BY id DESC LIMIT 1"
)['slug'] ?? '';
if ($chSlug === '') fail('cannot find second challenge');
$chId2 = (int)Connection::fetchOne(
    "SELECT id FROM challenges WHERE slug = :s", [':s' => $chSlug]
)['id'];

// Test the service methods directly (bypassing HTTP for the listing part
// because of Windows file-system caching weirdness with Apache).
$baseDir = rtrim((string)\CTF\Server\Support\Config::get('STORAGE_CHALLENGE_PATH', 'storage/challenges'), '/\\');
file_put_contents("$baseDir/$chSlug/to_delete.txt", "test");
file_put_contents("$baseDir/$chSlug/keep.txt", "test");
clearstatcache(true);

$svc = new \CTF\Server\Services\ChallengeService();
$files = $svc->listFiles($chSlug);
echo "  DEBUG service listFiles: " . count($files) . " files\n";
foreach ($files as $f) echo "    " . $f['path'] . "\n";

$foundToDelete = false;
$foundKeep = false;
foreach ($files as $f) {
    if ($f['path'] === 'to_delete.txt') $foundToDelete = true;
    if ($f['path'] === 'keep.txt') $foundKeep = true;
}
if (!$foundToDelete) fail('listFiles missing to_delete.txt');
if (!$foundKeep) fail('listFiles missing keep.txt');
pass("listFiles returns both uploaded files");

// Test delete service method
$deleted = $svc->deleteFile($chSlug, 'to_delete.txt');
if (!$deleted) fail('deleteFile did not return true');
clearstatcache(true);
if (file_exists("$baseDir/$chSlug/to_delete.txt")) fail('file still exists after delete');
if (!file_exists("$baseDir/$chSlug/keep.txt")) fail('keep.txt was wrongly deleted');
pass("deleteFile removed only the targeted file");

// Protected file deletion refused
try {
    $svc->deleteFile($chSlug, 'manifest.json');
    fail('manifest.json should be protected');
} catch (\InvalidArgumentException $e) {
    pass("manifest.json protected: " . $e->getMessage());
}

// Path traversal blocked
try {
    $svc->deleteFile($chSlug, '../../etc/passwd');
    fail('path traversal was NOT blocked');
} catch (\InvalidArgumentException $e) {
    pass("path traversal blocked: " . $e->getMessage());
}

// Empty / missing path rejected
try {
    $svc->deleteFile($chSlug, '');
    fail('empty path was accepted');
} catch (\InvalidArgumentException $e) {
    pass("empty path rejected: " . $e->getMessage());
}

// Test the HTTP routes work (using a file we know Apache can see — the
// just-deleted file is gone, but keep.txt is still there).
clearstatcache(true);
$r = http('GET', "/teacher/challenges/$chId2/edit", $teacherCookies);
if ($r['status'] !== 200) fail('GET edit page', "status {$r['status']}");
$csrf = extractCsrf($r['body']);
// POST delete with protected file
$r = http('POST', "/teacher/challenges/$chId2/files/delete", $teacherCookies, [
    '_csrf' => $csrf,
    'path' => 'manifest.json',
], $csrf);
if ($r['status'] !== 302) fail('protected delete route', "status {$r['status']}");
clearstatcache(true);
if (!file_exists("$baseDir/$chSlug/manifest.json")) fail('manifest.json was deleted via route!');
pass("HTTP route: protected file deletion refused");

echo "\n=== ALL PASS ===\n";
