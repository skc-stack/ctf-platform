<?php
/**
 * bin/seed-challenge.php — Publish a challenge ZIP directly to the DB.
 *
 * Stand-in for the full upload UI (§2.4). Reads a built ZIP, extracts
 * manifest.json, validates the schema, and inserts the challenge +
 * challenge_packages rows.
 *
 * Usage:
 *   php bin/seed-challenge.php --teacher=<username> --zip=<path-to-zip>
 *
 * Optional:
 *   --storage-dir=<path>  Where to copy the ZIP. Default:
 *                          <CTF_SERVER>/storage/challenges/
 */
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use CTF\Server\Services\ChallengeManifestParser;
use CTF\Server\Services\AuditLog;
use CTF\Server\Security\ZipValidator;
use CTF\Server\Support\Config;
use CTF\Server\Support\Logger;

// ----- parse args -----
$opts = getopt('', ['teacher:', 'zip:', 'storage-dir:']);
$teacher = $opts['teacher'] ?? null;
$zipPath = $opts['zip'] ?? null;
$storageDir = $opts['storage-dir']
    ?? Config::get('STORAGE_CHALLENGE_PATH')
    ?? 'storage/challenges';

if (!$teacher || !$zipPath) {
    fwrite(STDERR, "Usage: php bin/seed-challenge.php --teacher=<username> --zip=<path>\n");
    exit(2);
}
if (!is_file($zipPath)) {
    fwrite(STDERR, "ZIP not found: {$zipPath}\n");
    exit(2);
}

echo "==> ZIP: {$zipPath}\n";

// ----- validate ZIP (Slip protection + manifest existence) -----
$validator = new ZipValidator();
$result = $validator->validate($zipPath);
if (!$result['ok']) {
    fwrite(STDERR, "ZIP validation failed: {$result['reason']}\n");
    exit(1);
}

// ----- read + validate manifest.json -----
$zip = new ZipArchive();
$zip->open($zipPath);
$manifestJson = $zip->getFromName('manifest.json');
$zip->close();
if ($manifestJson === false) {
    fwrite(STDERR, "manifest.json not found at ZIP root\n");
    exit(1);
}
$manifest = json_decode($manifestJson, true);
try {
    $manifest = ChallengeManifestParser::validate($manifest);
} catch (\InvalidArgumentException $e) {
    fwrite(STDERR, "manifest invalid: " . $e->getMessage() . "\n");
    exit(1);
}
echo "==> manifest.json valid (challenge_id={$manifest['challenge_id']}, v{$manifest['version']})\n";

// ----- compute SHA-256 and stage the ZIP into storage -----
$sha256 = hash_file('sha256', $zipPath);
$stageDir = rtrim($storageDir, '/\\') . '/' . $manifest['challenge_id'] . '/v' . $manifest['version'];
if (!is_dir($stageDir)) {
    mkdir($stageDir, 0775, true);
}
$destPath = $stageDir . '/challenge.zip';
copy($zipPath, $destPath);
echo "==> staged: {$destPath}\n";

// ----- insert into DB -----
$chId = ChallengeManifestParser::insert($teacher, $manifest, $destPath, $sha256);
echo "==> challenge id: {$chId}\n";

// ----- audit -----
AuditLog::log('challenge_create', null, 'challenge', (string)$chId, [
    'teacher' => $teacher,
    'slug' => $manifest['challenge_id'],
    'version' => $manifest['version'],
    'sha256' => $sha256,
]);

echo "Done. Publish status: published.\n";
echo "Next: students on /student can see DEMO-001 and start a task.\n";
