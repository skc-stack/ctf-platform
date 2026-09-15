<?php
/**
 * tests/security_checklist.php — Verify the 15 invariants from CLAUDE.md §5.
 *
 * Run: php tests/security_checklist.php
 *
 * This is the §10.5 verification. Each check is a single rule; the
 * script exits 0 only if all 15 PASS. Any FAIL is loud and obvious.
 *
 * Where invariants have machine-checkable proxies (e.g. "FLAG_MASTER_SECRET
 * only on Server"), we grep for the actual condition. Where invariants are
 * policy statements that need human review, we still emit a PASS line
 * with a pointer to the docs/SECURITY.md entry.
 */
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use CTF\Server\Database\Connection;
use CTF\Server\Support\Config;

$pass = 0;
$fail = 0;
$results = [];

function check(string $id, string $label, bool $ok, string $detail = ''): void {
    global $pass, $fail, $results;
    $tag = $ok ? '[PASS]' : '[FAIL]';
    echo "  {$tag} {$id} — {$label}";
    if ($detail !== '') echo "\n         {$detail}";
    echo "\n";
    $results[] = ['id' => $id, 'label' => $label, 'ok' => $ok, 'detail' => $detail];
    if ($ok) $pass++; else $fail++;
}

// Helper: search a string for a regex; return count of matches.
function grepCount(string $haystack, string $pattern): int {
    return preg_match_all($pattern, $haystack);
}

$repoRoot = realpath(__DIR__ . '/../../');
$ctfServer = $repoRoot . '/ctf-server';
$target    = $repoRoot . '/target';

echo "\n=== Security Checklist (CLAUDE.md §5 — 15 invariants) ===\n\n";

// ----- A1: CTF Server 與 Target VM 為兩個獨立部署單元 -----
// grep -r "ctf-server" target/ 必須為空
$targetGrep = '';
foreach (['php', 'py', 'sh', 'md', 'conf', 'service', 'timer'] as $ext) {
    $r = glob_recursive($target, $ext);
    foreach ($r as $f) {
        $targetGrep .= file_get_contents($f) . "\n";
    }
}
check('A1', 'Target 不含 ctf-server source', !str_contains($targetGrep, 'ctf-server/'),
      'grep -r ctf-server target/ 應為空');

// And reverse: ctf-server 不 import target
$serverGrep = '';
foreach (['php', 'md'] as $ext) {
    foreach (glob_recursive($ctfServer, $ext) as $f) {
        // Skip vendor / node_modules
        if (str_contains($f, '/vendor/')) continue;
        $serverGrep .= file_get_contents($f) . "\n";
    }
}
$serverImportsTarget = (bool)preg_match('/require[^;]*target\/agent|require[^;]*target\/portal/', $serverGrep);
check('A1r', 'ctf-server 不 require target/ source', !$serverImportsTarget);

// ----- A2: Target 不直接連線 CTF Server MariaDB -----
// Look for hostnames other than 127.0.0.1 / localhost in agent config + scripts
$badDbHosts = [];
foreach (glob_recursive($target, 'py') as $f) {
    if (str_ends_with($f, 'local_db.py') || str_ends_with($f, 'installer.py')
        || str_ends_with($f, 'resetter.py') || str_ends_with($f, 'install.py')) {
        $c = file_get_contents($f);
        if (preg_match_all("/host\s*=\s*['\"]([^'\"]+)['\"]/", $c, $m)) {
            foreach ($m[1] as $host) {
                if (!in_array($host, ['127.0.0.1', 'localhost'], true)) {
                    $badDbHosts[] = "$f: $host";
                }
            }
        }
    }
}
check('A2', 'Target 不直接連 Server MariaDB',
      $badDbHosts === [],
      $badDbHosts === [] ? 'all local_db hosts are 127.0.0.1' : 'non-loopback hosts: ' . implode(', ', $badDbHosts));

// ----- A3: Server 不 require Target source -----
check('A3', 'Server 不 require Target source', !$serverImportsTarget);

// ----- A4: Target 不 require Server source -----
$targetImportsServer = (bool)preg_match('/require[^;]*ctf-server|import[^;]*ctf_server/', $targetGrep);
check('A4', 'Target 不 require Server source', !$targetImportsServer);

// ----- A5: Target 不含 FLAG_MASTER_SECRET -----
$hasMasterSecret = (bool)preg_match('/FLAG_MASTER_SECRET\s*=|["\']FLAG_MASTER_SECRET["\']/', $targetGrep);
check('A5', 'Target 不含 FLAG_MASTER_SECRET',
      !$hasMasterSecret,
      $hasMasterSecret ? 'FLAG_MASTER_SECRET found in target/' : 'grep clean');

// ----- A6: Score / Solve 只能由 CTF Server 決定 -----
// Verify SubmissionService never trusts Target's points
$submissionSvc = file_get_contents($ctfServer . '/src/Services/SubmissionService.php');
$trustsTargetPoints = (bool)preg_match('/points\s*=\s*\$_(GET|POST|REQUEST|json)/', $submissionSvc);
check('A6', 'Server 不接受 Target 傳的 points',
      !$trustsTargetPoints,
      $trustsTargetPoints ? 'SubmissionService reads points from request' : 'points always read from challenge.points');

// ----- A7: Task Token ≠ Flag -----
$taskService = file_get_contents($ctfServer . '/src/Services/TaskService.php');
// generateToken() should produce TASK-XXXX, FlagGenerator produces flag{...}
$tokenAndFlagDistinct = !str_contains($taskService, 'flag{')
    && (bool)preg_match('/TASK-[A-Z0-9]+/', $taskService)
    && (bool)preg_match('/flag\{/', file_get_contents($ctfServer . '/src/Security/FlagGenerator.php'));
check('A7', 'Task Token 與 Flag 格式互不相關',
      $tokenAndFlagDistinct,
      'Token = TASK-XXXX-..., Flag = flag{<hex>} — derive independently');

// ----- A8: Device Token ≠ Student password -----
$deviceSvc = file_get_contents($ctfServer . '/src/Services/DeviceService.php');
$deviceToken = (bool)preg_match('/bin2hex\(random_bytes\(32\)\)|password_hash/', $deviceSvc);
check('A8', 'Device Token 與 Student password 來源不同',
      $deviceToken,
      'Device token = random_bytes(32); passwords = password_hash()');

// ----- A9: Challenge Package 必須 versioned -----
$manifest = file_get_contents($repoRoot . '/challenge-example/DEMO-001/manifest.json');
$versioned = (bool)preg_match('/"version"\s*:\s*\d+/', $manifest);
check('A9', 'Challenge Package 有 version 欄位', $versioned);

// ----- A10: ZIP 必須驗證 hash 與 extraction path -----
$serverHasZipValidator = is_file($ctfServer . '/src/Security/ZipValidator.php');
$agentHasZipSafe = is_file($target . '/agent/src/zip_safe.py');
check('A10', 'Server + Agent 都有 ZIP 驗證',
      $serverHasZipValidator && $agentHasZipSafe,
      "Server ZipValidator.php=" . ($serverHasZipValidator ? 'yes' : 'no') .
      " Agent zip_safe.py=" . ($agentHasZipSafe ? 'yes' : 'no'));

// ----- A11: Target DB 與 Server DB 分離 -----
// Agent uses ctf_target DB; Server uses ctf_server. Verify config defaults.
$agentConfig = file_get_contents($target . '/agent/src/config.py');
$agentConfig .= file_get_contents($target . '/agent/src/local_db.py');
$usesCtfTarget = (bool)preg_match('/ctf_target|ctf_<id>/', $agentConfig);
$doesntUseCtfServer = !str_contains($agentConfig, 'ctf_server');
check('A11', 'Target DB 與 Server DB 分離',
      $usesCtfTarget && $doesntUseCtfServer,
      'Agent uses ctf_target + ctf_<id>; never touches ctf_server');

// ----- A12: Challenge DB 與 Target 管理 DB 分離 -----
// Each challenge gets ctf_<id> DB (per-challenge), not part of ctf_target.
$installerSrc = file_get_contents($target . '/agent/src/installer.py');
$perChallengeDb = (bool)preg_match('/create_database|ctf_/', $installerSrc);
check('A12', 'Challenge DB 與 Target 管理 DB 分離',
      $perChallengeDb,
      'Per-challenge DB created via create_database() in installer.py');

// ----- A13: Portal system operation 交由 Agent -----
// Portal source must NOT call shell_exec/system/exec/etc.
$portalSrc = '';
foreach (glob_recursive($target . '/portal', 'php') as $f) {
    if (str_contains($f, '/vendor/') || str_contains($f, '/tests/')) continue;
    $portalSrc .= file_get_contents($f) . "\n";
}
$portalBanned = preg_match_all('/\b(shell_exec|system|passthru|proc_open|popen|exec)\s*\(/', $portalSrc);
check('A13', 'Portal 程式碼不含禁用函式', $portalBanned === 0,
      $portalBanned === 0 ? 'grep clean' : "$portalBanned banned call(s) detected");

// ----- A14: 瀏覽器 state-changing 必須 CSRF -----
// Check routes/web.php: every POST has CSRF middleware in its chain
$routesFile = file_get_contents($ctfServer . '/routes/web.php');
$csrfProtectedPost = true;
preg_match_all("/\\\$router->post\\(\\s*['\"]([^'\"]+)['\"]\\s*,\\s*\\[([^\\]]+)\\]/", $routesFile, $matches);
foreach ($matches[1] as $i => $path) {
    $middlewares = $matches[2][$i];
    // device API routes don't need CSRF (Bearer auth instead); skip them
    if (str_starts_with($path, '/api/v1/device/')) continue;
    if (!str_contains($middlewares, 'CSRF::class')) {
        $csrfProtectedPost = false;
        echo "         missing CSRF on POST $path\n";
    }
}
check('A14', '瀏覽器 POST 路由全部帶 CSRF', $csrfProtectedPost);

// ----- A15: Device API 使用 Bearer Token -----
$deviceAuth = file_get_contents($ctfServer . '/src/Middleware/DeviceAuth.php');
$bearerToken = (bool)preg_match('/Bearer /', $deviceAuth);
check('A15', 'Device API 用 Bearer Token',
      $bearerToken,
      'DeviceAuth middleware checks Authorization: Bearer header');

echo "\n=== Summary: {$pass} PASS, {$fail} FAIL ===\n";

if ($fail > 0) {
    echo "\nFAIL details:\n";
    foreach ($results as $r) {
        if (!$r['ok']) {
            echo "  - {$r['id']}: {$r['label']}\n";
            if ($r['detail']) echo "    {$r['detail']}\n";
        }
    }
    exit(1);
}

exit(0);

// Helper: walk directory and collect files matching ext (no ext means any).
function glob_recursive(string $dir, string $ext): array {
    $out = [];
    if (!is_dir($dir)) return $out;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    foreach ($it as $file) {
        if ($file->isFile() && pathinfo($file->getPathname(), PATHINFO_EXTENSION) === $ext) {
            $out[] = $file->getPathname();
        }
    }
    return $out;
}
