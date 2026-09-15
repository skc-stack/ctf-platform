<?php
declare(strict_types=1);

namespace CTF\Server\Controllers\Teacher;

use CTF\Server\Controllers\BaseController;
use CTF\Server\Http\Request;
use CTF\Server\Http\Response;
use CTF\Server\Repositories\ChallengePackageRepository;
use CTF\Server\Repositories\ChallengeRepository;
use CTF\Server\Repositories\GroupRepository;
use CTF\Server\Services\ChallengeService;
use CTF\Server\Security\ZipValidator;

/**
 * Teacher-facing challenge management.
 *
 * Routes (see routes/web.php):
 *   GET    /teacher/challenges                          - list my challenges
 *   GET    /teacher/challenges/new                      - create form
 *   POST   /teacher/challenges                          - create (handles ZIP upload)
 *   GET    /teacher/challenges/{id}                     - show detail
 *   GET    /teacher/challenges/{id}/edit                - edit form
 *   POST   /teacher/challenges/{id}                     - update metadata
 *   POST   /teacher/challenges/{id}/upload             - upload new version
 *   POST   /teacher/challenges/{id}/publish            - publish (status=published)
 *   POST   /teacher/challenges/{id}/disable             - disable (status=disabled)
 *   POST   /teacher/challenges/{id}/delete              - delete challenge
 *   POST   /teacher/challenges/{id}/groups              - update group bindings
 */
final class ChallengeController extends BaseController
{
    public function __construct(
        private readonly ChallengeService $service = new ChallengeService(),
        private readonly ChallengeRepository $challenges = new ChallengeRepository(),
        private readonly ChallengePackageRepository $packages = new ChallengePackageRepository(),
        private readonly GroupRepository $groups = new GroupRepository(),
    ) {}

    /**
     * Validate that the current user is a teacher and owns this challenge.
     * Returns the challenge row or throws.
     */
    private function requireOwnChallenge(int $challengeId): array
    {
        $teacherId = (int)$_SESSION['user']['id'];
        $challenge = $this->challenges->findById($challengeId);
        if (!$challenge) {
            throw new \RuntimeException('題目不存在');
        }
        if ((int)$challenge['teacher_id'] !== $teacherId) {
            throw new \RuntimeException('你並非此題目的擁有者');
        }
        return $challenge;
    }

    /* ===== List ===== */

    public function index(Request $req): Response
    {
        $teacherId = (int)$_SESSION['user']['id'];
        $challenges = $this->challenges->listByTeacher($teacherId);
        return $this->view($req, 'teacher/challenges/index', [
            'title' => '我的題目 — CTF LAB',
            'challenges' => $challenges,
        ]);
    }

    /* ===== New (create form) ===== */

    public function new(Request $req): Response
    {
        $teacherId = (int)$_SESSION['user']['id'];
        return $this->view($req, 'teacher/challenges/new', [
            'title' => '建立新題目 — CTF LAB',
            'old' => $_SESSION['_old'] ?? [],
            'error' => $_SESSION['_flash_error'] ?? null,
        ]);
    }

    /* ===== Create ===== */

    public function create(Request $req): Response
    {
        $teacherId = (int)$_SESSION['user']['id'];

        $title = trim((string)($req->post['title'] ?? ''));
        $category = trim((string)($req->post['category'] ?? 'web'));
        $difficulty = trim((string)($req->post['difficulty'] ?? 'easy'));
        $description = trim((string)($req->post['description'] ?? ''));
        $points = (int)($req->post['points'] ?? 100);

        if ($title === '') {
            $this->flashError('標題為必填');
            $_SESSION['_old'] = $req->post;
            return Response::redirect('/teacher/challenges/new');
        }

        // Handle multi-file upload
        $uploadedFiles = [];
        \CTF\Server\Support\Logger::get()->debug('create: FILES=' . json_encode($_FILES));
        if (isset($_FILES['files']) && is_array($_FILES['files']['name'])) {
            foreach ($_FILES['files']['name'] as $index => $name) {
                $error = $_FILES['files']['error'][$index];
                if ($error === UPLOAD_ERR_NO_FILE) continue;
                if ($error !== UPLOAD_ERR_OK) {
                    $this->flashError('檔案上傳錯誤，錯誤代碼：' . $error);
                    $_SESSION['_old'] = $req->post;
                    return Response::redirect('/teacher/challenges/new');
                }
                $tmpName = $_FILES['files']['tmp_name'][$index];
                $isUploaded = is_uploaded_file($tmpName);
                \CTF\Server\Support\Logger::get()->debug("create: index=$index name=$name tmp=$tmpName is_uploaded=$isUploaded");
                if (!$isUploaded) {
                    $this->flashError('非法的上傳檔案');
                    $_SESSION['_old'] = $req->post;
                    return Response::redirect('/teacher/challenges/new');
                }
                // Use full_path if available (preserves web/ subdirectory);
                // fall back to basename otherwise.
                $displayName = $_FILES['files']['full_path'][$index] ?? $name;
                $uploadedFiles[] = [
                    'name' => $displayName,
                    'tmp_name' => $tmpName,
                    'size' => $_FILES['files']['size'][$index],
                ];
            }
        }
        \CTF\Server\Support\Logger::get()->debug('create: uploadedFiles=' . json_encode($uploadedFiles));

        try {
            $result = $this->service->createDraft(
                $teacherId,
                [
                    'title' => $title,
                    'category' => $category,
                    'difficulty' => $difficulty,
                    'description' => $description,
                    'points' => $points,
                    'files' => $uploadedFiles,  // pass uploaded files to service
                ],
                $req
            );

            // Debug: log the directory contents after createDraft
            $slug = $result['challenge']['slug'];
            $baseDir = rtrim((string)\CTF\Server\Support\Config::get('STORAGE_CHALLENGE_PATH', 'storage/challenges'), '/\\');
            $debugDir = $baseDir . '/' . $slug;
            \CTF\Server\Support\Logger::get()->debug("post-createDraft: $debugDir exists=" . (is_dir($debugDir) ? 'yes' : 'no') . " web_index=" . (is_file($debugDir . '/web/index.php') ? 'yes' : 'no'));

            $this->flashSuccess('題目已建立（識別碼 ' . $result['challenge']['slug'] . '）');
            return Response::redirect('/teacher/challenges/' . (int)$result['challenge']['id']);
        } catch (\InvalidArgumentException $e) {
            $this->flashError($e->getMessage());
            $_SESSION['_old'] = $req->post;
            return Response::redirect('/teacher/challenges/new');
        }
    }

    /* ===== Show (detail) ===== */

    public function show(Request $req, string $id): Response
    {
        try {
            $challenge = $this->requireOwnChallenge((int)$id);
        } catch (\RuntimeException $e) {
            $this->flashError($e->getMessage());
            return Response::redirect('/teacher/challenges');
        }
        $teacherId = (int)$_SESSION['user']['id'];
        $packageList = $this->packages->listByChallenge((int)$id);
        $latestPackage = $this->packages->findLatestByChallenge((int)$id);
        $manifest = null;
        if ($latestPackage !== null && !empty($latestPackage['manifest_json'])) {
            $manifest = json_decode($latestPackage['manifest_json'], true);
        }
        $groupBindings = $this->challenges->listGroupIdsForChallenge((int)$id);
        $allMyGroups = $this->groups->listByTeacher($teacherId);

        return $this->view($req, 'teacher/challenges/show', [
            'title' => $challenge['title'] . ' — CTF LAB',
            'challenge' => $challenge,
            'packages' => $packageList,
            'manifest' => $manifest,
            'groupBindings' => $groupBindings,
            'myGroups' => $allMyGroups,
            'error' => $_SESSION['_flash_error'] ?? null,
            'success' => $_SESSION['_flash_success'] ?? null,
        ]);
    }

    /* ===== Edit (form) ===== */

    public function edit(Request $req, string $id): Response
    {
        try {
            $challenge = $this->requireOwnChallenge((int)$id);
        } catch (\RuntimeException $e) {
            $this->flashError($e->getMessage());
            return Response::redirect('/teacher/challenges');
        }
        // Get the file list from disk
        $files = $this->service->listFiles($challenge['slug']);
        return $this->view($req, 'teacher/challenges/edit', [
            'title' => '編輯 ' . $challenge['title'],
            'challenge' => $challenge,
            'old' => $challenge,
            'files' => $files,
            'error' => $_SESSION['_flash_error'] ?? null,
            'success' => $_SESSION['_flash_success'] ?? null,
        ]);
    }

    /* ===== Update ===== */

    public function update(Request $req, string $id): Response
    {
        try {
            $this->requireOwnChallenge((int)$id);
        } catch (\RuntimeException $e) {
            $this->flashError($e->getMessage());
            return Response::redirect('/teacher/challenges');
        }

        $title = trim((string)($req->post['title'] ?? ''));
        $description = trim((string)($req->post['description'] ?? ''));
        $points = (int)($req->post['points'] ?? 100);

        if ($title === '') {
            $this->flashError('標題不可為空');
            return Response::redirect('/teacher/challenges/' . $id . '/edit');
        }

        $this->challenges->update((int)$id, [
            'title' => $title,
            'description' => $description,
            'points' => $points,
        ]);

        $this->flashSuccess('題目已更新');
        return Response::redirect('/teacher/challenges/' . $id);
    }

    /* ===== Upload new version (auto-package from directory) ===== */

    public function upload(Request $req, string $id): Response
    {
        try {
            $this->requireOwnChallenge((int)$id);
        } catch (\RuntimeException $e) {
            $this->flashError($e->getMessage());
            return Response::redirect('/teacher/challenges');
        }

        $teacherId = (int)$_SESSION['user']['id'];
        $challenge = $this->challenges->findById((int)$id);
        $slug = $challenge['slug'];

        // Handle multi-file upload (additional files to add to the directory)
        $uploadedFiles = [];
        if (isset($_FILES['files']) && is_array($_FILES['files']['name'])) {
            foreach ($_FILES['files']['name'] as $index => $name) {
                $error = $_FILES['files']['error'][$index];
                if ($error === UPLOAD_ERR_NO_FILE) continue;
                if ($error !== UPLOAD_ERR_OK) {
                    $this->flashError('檔案上傳錯誤，錯誤代碼：' . $error);
                    return Response::redirect('/teacher/challenges/' . $id);
                }
                if (!is_uploaded_file($_FILES['files']['tmp_name'][$index])) {
                    $this->flashError('非法的上傳檔案');
                    return Response::redirect('/teacher/challenges/' . $id);
                }
                $uploadedFiles[] = [
                    'name' => $_FILES['files']['name'][$index],
                    'tmp_name' => $_FILES['files']['tmp_name'][$index],
                    'size' => $_FILES['files']['size'][$index],
                ];
            }
        }

        // Save uploaded files to the challenge directory
        if (!empty($uploadedFiles)) {
            $this->service->saveUploadedFiles($slug, $uploadedFiles);
        }

        try {
            // Auto-package the current directory into a ZIP and store as new version
            $result = $this->service->uploadNewVersion($teacherId, (int)$id, $req);

            // DEBUG: write a test file DIRECTLY to the slug dir to see if it persists
            $baseDir = rtrim((string)\CTF\Server\Support\Config::get('STORAGE_CHALLENGE_PATH', 'storage/challenges'), '/\\');
            $testPath = $baseDir . '/' . $slug . '/_controller_test.txt';
            @file_put_contents($testPath, 'written at ' . microtime(true));
            \CTF\Server\Support\Logger::get()->debug('upload: test write to ' . $testPath . ' exists=' . (file_exists($testPath) ? 'y' : 'n') . ' size=' . (file_exists($testPath) ? filesize($testPath) : 'n/a'));

            $this->flashSuccess('新版本已封裝（v' . $result['challenge']['version'] . '）');
        } catch (\InvalidArgumentException $e) {
            $this->flashError($e->getMessage());
        } catch (\RuntimeException $e) {
            $this->flashError('封裝錯誤：' . $e->getMessage());
        }

        return Response::redirect('/teacher/challenges/' . $id);
    }

    /* ===== File management (from edit page) ===== */

    /**
     * Add files to the challenge directory. Form: POST with files[]
     * field. After upload, regenerates manifest.
     */
    public function addFiles(Request $req, string $id): Response
    {
        try {
            $challenge = $this->requireOwnChallenge((int)$id);
        } catch (\RuntimeException $e) {
            $this->flashError($e->getMessage());
            return Response::redirect('/teacher/challenges');
        }

        $teacherId = (int)$_SESSION['user']['id'];
        $uploadedFiles = [];
        if (isset($_FILES['files']) && is_array($_FILES['files']['name'])) {
            foreach ($_FILES['files']['name'] as $index => $name) {
                $error = $_FILES['files']['error'][$index];
                if ($error === UPLOAD_ERR_NO_FILE) continue;
                if ($error !== UPLOAD_ERR_OK) {
                    $this->flashError('檔案上傳錯誤，錯誤代碼：' . $error);
                    return Response::redirect('/teacher/challenges/' . $id . '/edit');
                }
                $tmpName = $_FILES['files']['tmp_name'][$index];
                if (!is_uploaded_file($tmpName)) {
                    $this->flashError('非法的上傳檔案');
                    return Response::redirect('/teacher/challenges/' . $id . '/edit');
                }
                $displayName = $_FILES['files']['full_path'][$index] ?? $name;
                $uploadedFiles[] = [
                    'name' => $displayName,
                    'tmp_name' => $tmpName,
                    'size' => $_FILES['files']['size'][$index],
                ];
            }
        }
        if (empty($uploadedFiles)) {
            $this->flashError('請選擇至少一個檔案');
            return Response::redirect('/teacher/challenges/' . $id . '/edit');
        }

        try {
            $result = $this->service->addFiles($challenge['slug'], $uploadedFiles, $teacherId, $req);
            $count = count($result['added']);
            $this->flashSuccess("已新增 {$count} 個檔案");
        } catch (\InvalidArgumentException $e) {
            $this->flashError($e->getMessage());
        }
        return Response::redirect('/teacher/challenges/' . $id . '/edit');
    }

    /**
     * Delete a single file from the challenge directory. Form: POST with
     * `path` field containing the relative file path.
     */
    public function deleteFile(Request $req, string $id): Response
    {
        try {
            $challenge = $this->requireOwnChallenge((int)$id);
        } catch (\RuntimeException $e) {
            $this->flashError($e->getMessage());
            return Response::redirect('/teacher/challenges');
        }

        $teacherId = (int)$_SESSION['user']['id'];
        $relPath = (string)($req->post['path'] ?? '');

        try {
            $deleted = $this->service->deleteFile($challenge['slug'], $relPath);
            if ($deleted) {
                \CTF\Server\Services\AuditLog::log('challenge_file_deleted', $teacherId, 'challenge', $challenge['slug'], [
                    'path' => $relPath,
                ]);
                $this->flashSuccess("已刪除 {$relPath}");
            } else {
                $this->flashError("找不到檔案: {$relPath}");
            }
        } catch (\InvalidArgumentException $e) {
            $this->flashError($e->getMessage());
        }
        return Response::redirect('/teacher/challenges/' . $id . '/edit');
    }

    /* ===== Publish ===== */

    public function publish(Request $req, string $id): Response
    {
        try {
            $teacherId = (int)$_SESSION['user']['id'];
            $this->requireOwnChallenge((int)$id);
            $this->service->publish($teacherId, (int)$id, $req);
            $this->flashSuccess('題目已發布');
        } catch (\Exception $e) {
            $this->flashError($e->getMessage());
        }
        return Response::redirect('/teacher/challenges/' . $id);
    }

    /* ===== Disable ===== */

    public function disable(Request $req, string $id): Response
    {
        try {
            $teacherId = (int)$_SESSION['user']['id'];
            $this->requireOwnChallenge((int)$id);
            $this->service->disable($teacherId, (int)$id, $req);
            $this->flashSuccess('題目已下架');
        } catch (\Exception $e) {
            $this->flashError($e->getMessage());
        }
        return Response::redirect('/teacher/challenges/' . $id);
    }

    /* ===== Delete ===== */

    public function delete(Request $req, string $id): Response
    {
        try {
            $teacherId = (int)$_SESSION['user']['id'];
            $this->requireOwnChallenge((int)$id);
            $this->service->delete($teacherId, (int)$id, $req);
            $this->flashSuccess('題目已刪除');
            return Response::redirect('/teacher/challenges');
        } catch (\Exception $e) {
            $this->flashError($e->getMessage());
            return Response::redirect('/teacher/challenges/' . $id);
        }
    }

    /* ===== Group bindings ===== */

    public function setGroups(Request $req, string $id): Response
    {
        try {
            $teacherId = (int)$_SESSION['user']['id'];
            $this->requireOwnChallenge((int)$id);
            $groupIds = array_map('intval', (array)($req->post['group_ids'] ?? []));
            $this->service->setGroupBindings($teacherId, (int)$id, $groupIds, $req);
            $this->flashSuccess('群組綁定已更新');
        } catch (\Exception $e) {
            $this->flashError($e->getMessage());
        }
        return Response::redirect('/teacher/challenges/' . $id);
    }

    /* ===== Helpers ===== */
}
