<?php
declare(strict_types=1);

namespace CTF\Server\Services;

use CTF\Server\Database\Connection;
use CTF\Server\Http\Request;
use CTF\Server\Repositories\ChallengePackageRepository;
use CTF\Server\Repositories\ChallengeRepository;
use CTF\Server\Repositories\UserRepository;
use CTF\Server\Services\ChallengeManifestParser;
use CTF\Server\Security\ZipValidator;
use CTF\Server\Support\Config;

/**
 * Challenge business logic: create, upload versions, publish, disable, group binding.
 *
 * Teachers can:
 *   - Create draft challenges (with optional ZIP for first version)
 *   - Upload new versions (bump version automatically)
 *   - Publish/disable challenges
 *   - Bind/unbind groups (visibility control)
 *   - Update metadata (title, description, category, difficulty, points)
 *
 * Challenges start as draft. Only published challenges are visible to students.
 * Only the teacher who created a challenge can modify it.
 */
final class ChallengeService
{
    public function __construct(
        private readonly ChallengeRepository $challenges = new ChallengeRepository(),
        private readonly ChallengePackageRepository $packages = new ChallengePackageRepository(),
        private readonly UserRepository $users = new UserRepository(),
        private readonly ChallengeManifestParser $manifestParser = new ChallengeManifestParser(),
        private readonly ZipValidator $zipValidator = new ZipValidator(),
    ) {}

    /**
     * Create a new draft challenge (status=draft).
     * Slug is auto-generated from difficulty + category + sequence number
     * (e.g. `easy-web-001`, `medium-crypto-002`).
     *
     * @param int $teacherId The teacher who owns this challenge.
     * @param array<string,mixed> $metadata Fields: title, category, difficulty, description, points (optional), files (optional, list of uploaded files).
     * @param Request|null $req Request for audit logging (optional).
     * @return array{challenge: array<string,mixed>}
     *         Returns the created challenge.
     */
    public function createDraft(
        int $teacherId,
        array $metadata,
        ?Request $req = null
    ): array {
        // Validate teacher
        $teacher = $this->users->findById($teacherId);
        if (!$teacher || $teacher['role'] !== UserRepository::ROLE_TEACHER) {
            throw new \InvalidArgumentException('只有老師可以建立題目');
        }

        // Validate required metadata (no slug — it's auto-generated)
        foreach (['title', 'category', 'difficulty'] as $field) {
            if (empty($metadata[$field])) {
                throw new \InvalidArgumentException("欄位 {$field} 為必填");
            }
        }

        // Auto-generate slug: {difficulty}-{category}-{NNN}
        $slug = $this->generateUniqueSlug($metadata['difficulty'], $metadata['category']);

        $metadata['points'] = $metadata['points'] ?? 100;

        // Save challenge first (status=draft)
        $challengeId = $this->challenges->create([
            'teacher_id' => $teacherId,
            'title' => $metadata['title'],
            'slug' => $slug,
            'category' => $metadata['category'],
            'difficulty' => $metadata['difficulty'],
            'description' => $metadata['description'] ?? null,
            'points' => (int)$metadata['points'],
            'version' => 1,  // start at version 1
            'status' => ChallengeRepository::STATUS_DRAFT,
        ]);

        // Create challenge directory structure
        $this->createChallengeDirectory($slug);

        // Move uploaded files to challenge directory
        $files = $metadata['files'] ?? [];
        \CTF\Server\Support\Logger::get()->debug("createDraft: slug=$slug files=" . json_encode($files));
        if (!empty($files)) {
            $this->saveUploadedFiles($slug, $files);
            // Regenerate manifest after files are added
            $this->generateManifest($slug);
            \CTF\Server\Support\Logger::get()->debug("createDraft: manifest generated, exists=" . (is_file(rtrim((string)Config::get('STORAGE_CHALLENGE_PATH', 'storage/challenges'), '/\\') . '/' . $slug . '/manifest.json') ? 'yes' : 'no'));
        }

        if ($req !== null) {
            AuditLog::log('challenge_create', $teacherId, 'challenge', (string)$challengeId, [
                'title' => $metadata['title'],
                'slug' => $slug,
                'file_count' => count($files),
            ]);
        }

        return [
            'challenge' => $this->challenges->findById($challengeId) ?? [],
        ];
    }

    /**
     * Create the challenge directory structure.
     *
     * @param string $slug Challenge slug (e.g., easy-web-001)
     * @return string Path to the challenge directory
     */
    private function createChallengeDirectory(string $slug): string
    {
        $baseDir = rtrim((string)Config::get('STORAGE_CHALLENGE_PATH', 'storage/challenges'), '/\\');
        $dir = $baseDir . '/' . $slug;

        // Create directory structure
        $dirs = [
            $dir,
            $dir . '/web',
        ];

        foreach ($dirs as $d) {
            if (!is_dir($d)) {
                mkdir($d, 0775, true);
            }
        }

        return $dir;
    }

    /**
     * Save uploaded files to the challenge directory.
     *
     * Files are placed in their relative path. For example:
     *   "web/index.php" -> /challenges/{slug}/web/index.php
     *   "setup.sql"     -> /challenges/{slug}/setup.sql
     *
     * @param array<int,array{name:string, tmp_name:string, size:int}> $files
     */
    public function saveUploadedFiles(string $slug, array $files): void
    {
        $baseDir = rtrim((string)Config::get('STORAGE_CHALLENGE_PATH', 'storage/challenges'), '/\\');
        $challengeDir = $baseDir . '/' . $slug;

        foreach ($files as $file) {
            $name = $file['name'];
            $tmp = $file['tmp_name'];

            // Sanitize path: prevent directory traversal
            $name = str_replace('\\', '/', $name);
            // Remove any ../ sequences
            $name = preg_replace('#\.\./#', '', $name);
            // Remove leading slashes
            $name = ltrim($name, '/');

            if (empty($name)) continue;

            $target = $challengeDir . '/' . $name;
            $targetDir = dirname($target);

            // Create subdirectories if needed
            if (!is_dir($targetDir)) {
                mkdir($targetDir, 0775, true);
            }

            // Use file_get_contents + file_put_contents (avoids move_uploaded_file
            // quirks on Windows + Apache, where the temp file can be cleaned up
            // before our service has a chance to actually write the destination).
            \CTF\Server\Support\Logger::get()->debug("saveUploadedFiles: reading $tmp (size=" . filesize($tmp) . ")");
            $content = @file_get_contents($tmp);
            if ($content === false) {
                throw new \RuntimeException("無法讀取上傳檔案: $name");
            }
            // Pre-create parent dir before write to avoid race.
            $targetParent = dirname($target);
            if (!is_dir($targetParent)) {
                @mkdir($targetParent, 0775, true);
            }
            // Use fopen/fwrite for synchronous write (avoids Windows file cache
            // weirdness where file_put_contents shows success but file is gone).
            $fh = @fopen($target, 'wb');
            if ($fh === false) {
                throw new \RuntimeException("無法開啟目標檔案: $target");
            }
            $bytes = @fwrite($fh, $content);
            @fflush($fh);
            @fclose($fh);
            clearstatcache(true);
            \CTF\Server\Support\Logger::get()->debug("saveUploadedFiles: wrote $target bytes=$bytes size_now=" . (is_file($target) ? filesize($target) : 'n/a'));
            if ($bytes === false) {
                throw new \RuntimeException("無法寫入上傳檔案: $name");
            }
            // Don't unlink the temp file here — let PHP's request cleanup do it.
        }
    }

    /**
     * Generate manifest.json from challenge metadata.
     *
     * @param string $slug Challenge slug
     * @param array<string,mixed> $metadata Optional override fields
     */
    public function generateManifest(string $slug, array $metadata = []): void
    {
        $challenge = $this->challenges->findBySlug($slug);
        if (!$challenge) {
            throw new \InvalidArgumentException("Challenge not found: $slug");
        }

        $manifest = [
            'schema_version' => 1,
            'challenge_id' => $slug,
            'version' => (int)($challenge['version'] ?? 1),
            'type' => $challenge['category'],
            'difficulty' => $challenge['difficulty'],
            'title' => $challenge['title'],
            'description' => $challenge['description'] ?? '',
            'points' => (int)($challenge['points'] ?? 100),
            'entrypoint' => '/challenge/' . $slug . '/',
            'verification' => [
                'type' => 'automatic',
                'automatic' => [
                    'script' => 'check.sh',
                ],
            ],
            'database' => null,
            'reset' => [
                'drop_and_recreate_db' => false,
                'restore_files' => ['web/'],
            ],
        ];

        // Allow override from metadata
        if (!empty($metadata)) {
            $manifest = array_merge($manifest, $metadata);
        }

        $baseDir = rtrim((string)Config::get('STORAGE_CHALLENGE_PATH', 'storage/challenges'), '/\\');
        $manifestPath = $baseDir . '/' . $slug . '/manifest.json';

        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        \CTF\Server\Support\Logger::get()->debug("generateManifest: writing to $manifestPath, json_len=" . strlen($json));
        $result = file_put_contents($manifestPath, $json);
        \CTF\Server\Support\Logger::get()->debug("generateManifest: file_put_contents result=" . var_export($result, true) . " exists_after=" . (is_file($manifestPath) ? 'yes' : 'no'));
    }

    /**
     * Get the absolute path to the challenge directory on disk.
     */
    public function getChallengeDir(string $slug): string
    {
        $baseDir = rtrim((string)Config::get('STORAGE_CHALLENGE_PATH', 'storage/challenges'), '/\\');
        return $baseDir . '/' . $slug;
    }

    /**
     * List all files in the challenge directory (excluding manifest.json, ZIP,
     * and hidden files). Returns relative paths.
     *
     * @return array<int,array{path:string,size:int,modified:int}>
     */
    public function listFiles(string $slug): array
    {
        $challengeDir = $this->getChallengeDir($slug);
        if (!is_dir($challengeDir)) {
            return [];
        }
        $files = [];
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($challengeDir, \RecursiveDirectoryIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if (!$file->isFile()) continue;
                $rel = str_replace($challengeDir . DIRECTORY_SEPARATOR, '', $file->getPathname());
                $rel = str_replace('\\', '/', $rel);
                // Skip manifest.json, ZIP, and hidden files
                if ($rel === 'manifest.json') continue;
                if (str_starts_with($rel, 'challenge-v') && str_ends_with($rel, '.zip')) continue;
                if (str_starts_with($rel, '.')) continue;
                $files[] = [
                    'path' => $rel,
                    'size' => $file->getSize(),
                    'modified' => $file->getMTime(),
                ];
            }
            usort($files, fn($a, $b) => strcmp($a['path'], $b['path']));
        } catch (\Throwable $e) {
            // Unreadable directory — return empty list.
        }
        return $files;
    }

    /**
     * Delete a file from the challenge directory. Refuses to delete
     * manifest.json or any challenge-v*.zip (these are managed by the
     * system, not the teacher).
     *
     * @param string $slug Challenge slug
     * @param string $relPath Relative path (from form)
     * @return bool true if a file was actually deleted
     * @throws \InvalidArgumentException for protected paths or path traversal
     */
    public function deleteFile(string $slug, string $relPath): bool
    {
        $challengeDir = $this->getChallengeDir($slug);

        // Sanitize: no leading slashes, no ..
        $relPath = ltrim($relPath, '/\\');
        $relPath = str_replace('\\', '/', $relPath);
        if (str_contains($relPath, '..')) {
            throw new \InvalidArgumentException('路徑含有非法字元');
        }

        // Refuse to delete protected files
        if ($relPath === 'manifest.json') {
            throw new \InvalidArgumentException('manifest.json 是系統檔案，無法刪除');
        }
        if (preg_match('#^challenge-v\d+\.zip$#', $relPath)) {
            throw new \InvalidArgumentException('ZIP 套件是系統檔案，無法刪除');
        }

        $target = $challengeDir . '/' . $relPath;

        // Verify the resolved path is inside the challenge dir
        $real = realpath($target);
        $realBase = realpath($challengeDir);
        if ($real === false || $realBase === false || !str_starts_with($real, $realBase . DIRECTORY_SEPARATOR)) {
            throw new \InvalidArgumentException('路徑不在題目目錄內');
        }

        if (!is_file($real)) {
            return false;
        }
        return @unlink($real);
    }

    /**
     * Add files to the challenge directory (overwriting any existing
     * files at the same relative path). Public alias used by the
     * upload route.
     *
     * @param array<int,array{name:string, tmp_name:string, size:int}> $files
     * @param int|null $teacherId Optional, for audit log
     * @param Request|null $req Optional, for audit log
     * @return array{added:array<int,string>,skipped:array<int,string>}
     */
    public function addFiles(string $slug, array $files, ?int $teacherId = null, ?Request $req = null): array
    {
        $added = [];
        $skipped = [];
        foreach ($files as $file) {
            try {
                $this->saveUploadedFiles($slug, [$file]);
                $added[] = $file['name'];
            } catch (\Throwable $e) {
                $skipped[] = $file['name'] . ' (' . $e->getMessage() . ')';
            }
        }
        // Regenerate manifest after files added
        $this->generateManifest($slug);

        if ($teacherId !== null && !empty($added)) {
            AuditLog::log('challenge_files_added', $teacherId, 'challenge', $slug, [
                'files' => $added,
            ]);
        }
        return ['added' => $added, 'skipped' => $skipped];
    }

    /**
     * Upload new version from the challenge directory.
     * Packages the current directory into a ZIP and stores it.
     *
     * @param int $teacherId The teacher who owns this challenge.
     * @param int $challengeId The challenge ID.
     * @param ?Request $req Request for audit logging (optional).
     * @return array{challenge: array<string,mixed>, package: array<string,mixed>}
     */
    public function uploadNewVersion(
        int $teacherId,
        int $challengeId,
        ?Request $req = null
    ): array {
        // Validate teacher owns this challenge
        $challenge = $this->challenges->findById($challengeId);
        if ($challenge === null) {
            throw new \InvalidArgumentException('題目不存在');
        }
        if ((int)$challenge['teacher_id'] !== $teacherId) {
            throw new \InvalidArgumentException('你並非此題目的擁有者');
        }

        $slug = $challenge['slug'];
        $currentVersion = (int)($challenge['version'] ?? 0);
        $newVersion = $currentVersion + 1;

        // Update manifest with new version
        $this->generateManifest($slug, ['version' => $newVersion]);

        // Create ZIP from challenge directory
        $zipPath = $this->createChallengeZip($slug, $newVersion);

        // Store ZIP and package
        $packageId = $this->storeZipAsPackage(
            $challengeId,
            $newVersion,
            $zipPath,
            null
        );

        // Update challenge version
        $this->challenges->update($challengeId, ['version' => $newVersion]);

        if ($req !== null) {
            AuditLog::log('challenge_upload', $teacherId, 'challenge', (string)$challengeId, [
                'version' => $newVersion,
                'package_id' => $packageId,
            ]);
        }

        // Clean up temp ZIP
        if (is_file($zipPath)) {
            @unlink($zipPath);
        }

        return [
            'challenge' => $this->challenges->findById($challengeId) ?? [],
            'package'   => $this->packages->findById($packageId) ?? [],
        ];
    }

    /**
     * Create a ZIP archive from the challenge directory.
     *
     * @param string $slug Challenge slug
     * @param int $version Version number
     * @return string Path to the created ZIP file
     */
    private function createChallengeZip(string $slug, int $version): string
    {
        $baseDir = rtrim((string)Config::get('STORAGE_CHALLENGE_PATH', 'storage/challenges'), '/\\');
        $challengeDir = $baseDir . '/' . $slug;
        $zipPath = $challengeDir . '/challenge-v' . $version . '.zip';

        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('無法建立 ZIP 檔案');
        }

        // Add all files from challenge directory (excluding the ZIP itself)
        $this->addDirectoryToZip($zip, $challengeDir, '', [$zipPath]);

        $zip->close();

        return $zipPath;
    }

    /**
     * Recursively add directory contents to ZIP.
     */
    private function addDirectoryToZip(\ZipArchive $zip, string $sourceDir, string $relativePath, array $excludePaths = []): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceDir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            $filePath = $file->getPathname();

            // Skip excluded paths
            if (in_array($filePath, $excludePaths, true)) {
                continue;
            }

            $relative = $relativePath . $file->getBasename();
            if ($file->isDir()) {
                $zip->addEmptyDir($relative);
            } else {
                $zip->addFile($filePath, $relative);
            }
        }
    }

    /**
     * Publish a draft challenge.
     *
     * @param int $teacherId The teacher who owns this challenge.
     * @param int $challengeId The challenge ID.
     * @param Request|null $req Request for audit logging (optional).
     * @return bool True if published.
     */
    public function publish(int $teacherId, int $challengeId, ?Request $req = null): bool {
        // Validate teacher owns this challenge
        $challenge = $this->challenges->findById($challengeId);
        if ($challenge === null) {
            throw new \InvalidArgumentException('題目不存在');
        }
        if ((int)$challenge['teacher_id'] !== $teacherId) {
            throw new \InvalidArgumentException('你並非此題目的擁有者');
        }

        // Must have at least one package
        $latest = $this->packages->findLatestByChallenge($challengeId);
        if ($latest === null) {
            throw new \InvalidArgumentException('題目必須包含至少一個版本才能發布');
        }

        $affected = $this->challenges->publish($challengeId);
        if ($affected && $req !== null) {
            AuditLog::log('challenge_publish', $teacherId, 'challenge', (string)$challengeId, [
                'title' => $challenge['title'],
                'version' => (int)$latest['version'],
            ]);
        }
        return $affected;
    }

    /**
     * Disable a published challenge.
     *
     * @param int $teacherId The teacher who owns this challenge.
     * @param int $challengeId The challenge ID.
     * @param Request|null $req Request for audit logging (optional).
     * @return bool True if disabled.
     */
    public function disable(int $teacherId, int $challengeId, ?Request $req = null): bool {
        // Validate teacher owns this challenge
        $challenge = $this->challenges->findById($challengeId);
        if ($challenge === null) {
            throw new \InvalidArgumentException('題目不存在');
        }
        if ((int)$challenge['teacher_id'] !== $teacherId) {
            throw new \InvalidArgumentException('你並非此題目的擁有者');
        }

        $affected = $this->challenges->disable($challengeId);
        if ($affected && $req !== null) {
            AuditLog::log('challenge_disable', $teacherId, 'challenge', (string)$challengeId, [
                'title' => $challenge['title'],
                'version' => (int)($this->packages->findLatestByChallenge($challengeId)['version'] ?? 0),
            ]);
        }
        return $affected;
    }

    /**
     * Delete a challenge (and all its packages).
     *
     * @param int $teacherId The teacher who owns this challenge.
     * @param int $challengeId The challenge ID.
     * @param Request|null $req Request for audit logging (optional).
     * @return bool True if deleted.
     */
    public function delete(int $teacherId, int $challengeId, ?Request $req = null): bool {
        // Validate teacher owns this challenge
        $challenge = $this->challenges->findById($challengeId);
        if ($challenge === null) {
            throw new \InvalidArgumentException('題目不存在');
        }
        if ((int)$challenge['teacher_id'] !== $teacherId) {
            throw new \InvalidArgumentException('你並非此題目的擁有者');
        }

        $packageCount = $this->packages->listByChallenge($challengeId);
        $affected = $this->challenges->delete($challengeId);
        if ($affected && $req !== null) {
            AuditLog::log('challenge_delete', $teacherId, 'challenge', (string)$challengeId, [
                'title' => $challenge['title'],
                'package_count' => count($packageCount),
            ]);
        }
        return $affected;
    }

    /**
     * Bind a challenge to N groups (replaces any prior bindings).
     *
     * @param int $teacherId The teacher who owns this challenge.
     * @param int $challengeId The challenge ID.
     * @param array<int> $groupIds Array of group IDs.
     * @param Request|null $req Request for audit logging (optional).
     * @return bool True if updated.
     */
    public function setGroupBindings(
        int $teacherId,
        int $challengeId,
        array $groupIds,
        ?Request $req = null
    ): bool {
        // Validate teacher owns this challenge
        $challenge = $this->challenges->findById($challengeId);
        if ($challenge === null) {
            throw new \InvalidArgumentException('題目不存在');
        }
        if ((int)$challenge['teacher_id'] !== $teacherId) {
            throw new \InvalidArgumentException('你並非此題目的擁有者');
        }

        $this->challenges->setGroupBindings($challengeId, $groupIds);

        if ($req !== null) {
            AuditLog::log('challenge_set_groups', $teacherId, 'challenge', (string)$challengeId, [
                'group_count' => count($groupIds),
            ]);
        }
        return true;
    }

    /**
     * Store a ZIP file as a new package.
     *
     * @param int $challengeId The challenge ID.
     * @param int $version The version number.
     * @param string $zipPath Path to the ZIP file.
     * @param Request|null $req Request for audit logging (optional).
     * @return int New package ID.
     */
    private function storeZipAsPackage(
        int $challengeId,
        int $version,
        string $zipPath,
        ?Request $req = null
    ): int {
        // Ensure storage directory exists
        $storagePath = rtrim((string)Config::get('STORAGE_CHALLENGE_PATH'), '/\\') . '/' .
            $challengeId . '/v' . $version . '/challenge.zip';
        $storageDir = dirname($storagePath);
        if (!is_dir($storageDir)) {
            mkdir($storageDir, 0775, true);
        }

        // Copy ZIP to storage
        if (!copy($zipPath, $storagePath)) {
            throw new \RuntimeException('無法複製 ZIP 檔案到儲存位置');
        }

        // Verify SHA-256 matches
        $storedSha256 = hash_file('sha256', $storagePath);
        $zipSha256 = hash_file('sha256', $zipPath);
        if ($storedSha256 !== $zipSha256) {
            @unlink($storagePath);
            throw new \RuntimeException('ZIP 檔案 SHA-256 不匹配');
        }

        // Create package record
        $packageId = $this->packages->create([
            'challenge_id' => $challengeId,
            'version' => $version,
            'file_path' => $storagePath,
            'original_name' => basename($zipPath),
            'file_size' => filesize($zipPath),
            'sha256' => $zipSha256,
            'manifest_json' => $this->extractManifestFromZip($zipPath),
        ]);

        if ($req !== null) {
            AuditLog::log('challenge_upload_package', $teacherId, 'challenge', (string)$challengeId, [
                'version' => $version,
                'package_id' => $packageId,
            ]);
        }

        return $packageId;
    }

    /**
     * Extract manifest.json from a ZIP file.
     *
     * @param string $zipPath Path to the ZIP file.
     * @return string The manifest.json content.
     */
    private function extractManifestFromZip(string $zipPath): string
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new \RuntimeException('無法開啟 ZIP 檔案');
        }
        $manifestBytes = $zip->getFromName('manifest.json');
        $zip->close();
        if ($manifestBytes === false) {
            throw new \RuntimeException('ZIP 中缺少 manifest.json');
        }
        return (string)$manifestBytes;
    }

    /**
     * Generate a unique slug of the form `{difficulty}-{category}-{NNN}`.
     *
     * Sequence number is per (difficulty, category) combination. For example:
     *   easy-web-001, easy-web-002, medium-web-001, hard-pwn-001.
     *
     * Retries up to 10 times on collision (e.g. concurrent inserts), then
     * falls back to a 4-char random suffix.
     */
    private function generateUniqueSlug(string $difficulty, string $category): string
    {
        $prefix = strtolower($difficulty) . '-' . strtolower($category);
        for ($i = 0; $i < 10; $i++) {
            $next = $this->nextSequenceNumber($prefix);
            $slug = sprintf('%s-%03d', $prefix, $next);
            if ($this->challenges->findBySlug($slug) === null) {
                return $slug;
            }
        }
        // Fallback: random 4-char hex suffix
        return $prefix . '-' . bin2hex(random_bytes(2));
    }

    /**
     * Find the next available sequence number for a given prefix.
     * Returns MAX(last_segment) + 1, or 1 if no existing slugs.
     */
    private function nextSequenceNumber(string $prefix): int
    {
        $row = Connection::fetchOne(
            "SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(slug, '-', -1) AS UNSIGNED)), 0) AS max_n
             FROM challenges
             WHERE slug LIKE :prefix",
            [':prefix' => $prefix . '-%']
        );
        return (int)($row['max_n'] ?? 0) + 1;
    }
}