<?php
declare(strict_types=1);

namespace CTF\Server\Controllers;

use CTF\Server\Http\Request;
use CTF\Server\Http\Response;
use CTF\Server\Repositories\TaskSessionRepository;
use CTF\Server\Services\TaskService;

/**
 * Task Session controller.
 *
 * Routes:
 *   POST /api/v1/student/task/start        — browser, CSRF, start a task
 *   GET  /student/task/{id}                — browser, show the task token
 *   POST /student/task/{id}/cancel         — browser, CSRF, cancel a task
 *   POST /api/v1/device/task/validate      — device (DeviceAuth), validate token
 */
final class TaskController extends BaseController
{
    public function __construct(
        private readonly TaskService $service = new TaskService(),
        private readonly TaskSessionRepository $tasks = new TaskSessionRepository(),
    ) {}

    /* ===== Browser: student ===== */

    public function start(Request $req): Response
    {
        $studentId = (int)$_SESSION['user']['id'];
        $challengeId = (int)($req->post['challenge_id'] ?? 0);
        if ($challengeId === 0) {
            $this->flashError('請提供 challenge_id');
            return Response::redirect($req->headers['Referer'] ?? '/student');
        }
        try {
            $result = $this->service->start($studentId, $challengeId, null, $req);
            $ttl = (int)\CTF\Server\Support\Config::get('DEFAULT_TASK_TTL', 120);
            $this->flashSuccess("Task Token 已建立，請在 {$ttl} 分鐘內貼到 Target Portal");
            // Store token in session so it persists across page refreshes
            $_SESSION['task_tokens'][(int)$result['task']['id']] = $result['token'];
            // Render show view directly so we can pass the plain token
            $task = $this->tasks->findById((int)$result['task']['id']);
            return $this->view($req, 'student/task/show', [
                'title' => 'Task Token — CTF LAB',
                'task' => $task,
                'task_token' => $result['token'],
            ]);
        } catch (\InvalidArgumentException $e) {
            $this->flashError($e->getMessage());
            return Response::redirect($req->headers['Referer'] ?? '/student');
        }
    }

    public function show(Request $req, string $id): Response
    {
        $studentId = (int)$_SESSION['user']['id'];
        $task = $this->tasks->findById((int)$id);
        if (!$task || (int)$task['student_id'] !== $studentId) {
            $this->flashError('找不到此任務');
            return Response::redirect('/student');
        }
        // Try to get token from session (stored when task was first created)
        $task_token = $_SESSION['task_tokens'][(int)$id] ?? null;
        return $this->view($req, 'student/task/show', [
            'title' => 'Task Token — CTF LAB',
            'task' => $task,
            'task_token' => $task_token,
        ]);
    }

    public function cancel(Request $req, string $id): Response
    {
        $studentId = (int)$_SESSION['user']['id'];
        try {
            $this->service->cancel((int)$id, $studentId, $req);
            $this->flashSuccess('任務已取消');
        } catch (\InvalidArgumentException $e) {
            $this->flashError($e->getMessage());
        }
        return Response::redirect('/student');
    }

    /* ===== Device API ===== */

    public function validateApi(Request $req): Response
    {
        $device = $req->device ?? null;
        if ($device === null) {
            return $this->jsonError('Device not authenticated', 401);
        }
        $token = (string)($req->post['task_token'] ?? $req->json()['task_token'] ?? '');
        try {
            $result = $this->service->validate($token, $device, $req);
            // Update task status to 'validated' when device validates token
            $this->tasks->updateTaskStatus((int)$result['task']['id'], TaskSessionRepository::TASK_STATUS_VALIDATED);
            // Compute the dynamic flag for this student + challenge + task
            $flag = \CTF\Server\Security\FlagGenerator::compute(
                (int)$result['task']['student_id'],
                (string)$result['challenge']['uuid'],
                (string)$result['task']['uuid']
            );
            return $this->jsonOk([
                'task_id' => (int)$result['task']['id'],
                'task_uuid' => (string)$result['task']['uuid'],
                'challenge_id' => (int)$result['challenge']['id'],
                'challenge_uuid' => (string)$result['challenge']['uuid'],
                'flag' => $flag,
                'entrypoint' => '/challenge/start/' . (string)$result['challenge']['slug'] . '/?task_id=' . (int)$result['task']['id'],
                'challenge_version' => (int)$result['challenge']['version'],
                'expires_at' => (string)$result['task']['expires_at'],
            ]);
        } catch (\CTF\Server\Services\TaskValidationException $e) {
            return $this->jsonError($e->getMessage(), $e->httpStatus, ['code' => $e->errorCode]);
        }
    }

    /**
     * POST /api/v1/device/challenge/start
     * Called by Target Portal when student enters a challenge page.
     * Records cumulative solve time for the task.
     *
     * Body: { task_id: int }
     * Returns: { challenge_time_seconds: int, challenge_started_at: string }
     */
    public function startChallengeApi(Request $req): Response
    {
        $device = $req->device ?? null;
        if ($device === null) {
            return $this->jsonError('Device not authenticated', 401);
        }
        $data = $req->isJson() ? $req->json() : $req->post;
        $taskId = (int)($data['task_id'] ?? 0);
        if ($taskId === 0) {
            return $this->jsonError('task_id is required', 400);
        }
        $result = $this->tasks->startChallenge($taskId);
        if ($result === null) {
            return $this->jsonError('Task not found or not active', 404);
        }
        // Also update task status to 'challenge_started'
        $this->tasks->updateTaskStatus($taskId, TaskSessionRepository::TASK_STATUS_STARTED);
        return $this->jsonOk($result);
    }

    /**
     * GET /api/v1/student/task/{id}/status
     * Returns current task status for polling.
     */
    public function statusApi(Request $req, string $id): Response
    {
        $studentId = (int)$_SESSION['user']['id'];
        $taskId = (int)$id;
        // If the ID is 0 or negative, it's invalid
        if ($taskId <= 0) {
            return $this->jsonError('Invalid task ID', 400);
        }
        $task = $this->tasks->findById($taskId);
        if (!$task || (int)$task['student_id'] !== $studentId) {
            return $this->jsonError('Task not found', 404);
        }
        return $this->jsonOk([
            'task_id' => (int)$task['id'],
            'task_status' => (string)($task['task_status'] ?? 'token_not_copied'),
            'status' => (string)$task['status'],
            'expires_at' => (string)$task['expires_at'],
            'challenge_slug' => (string)$task['challenge_slug'],
        ]);
    }

    /**
     * POST /api/v1/student/task/{id}/status
     * Update task status (copy token, start challenge).
     */
    public function updateStatusApi(Request $req, string $id): Response
    {
        $studentId = (int)$_SESSION['user']['id'];
        $task = $this->tasks->findById((int)$id);
        if (!$task || (int)$task['student_id'] !== $studentId) {
            return $this->jsonError('Task not found', 404);
        }
        $newStatus = $req->json()['task_status'] ?? '';
        $ok = $this->tasks->updateTaskStatus((int)$id, $newStatus);
        if (!$ok) {
            return $this->jsonError('Invalid status or task not active', 400);
        }
        return $this->jsonOk(['task_status' => $newStatus]);
    }

    /**
     * GET /api/v1/task/flag
     * Get flag for a completed challenge.
     *
     * Called by Target VM's getflag() after check() returns true.
     *
     * Query params:
     *   - task_id: int (required)
     *   - challenge_slug: string (required)
     *
     * Returns: { flag: string }
     *
     * Security:
     *   - Task must belong to the student making the request
     *   - Task must be in 'active' status and not expired
     *   - This endpoint does NOT mark the task as completed
     *     (flag submission is a separate flow)
     */
    public function getFlagApi(Request $req): Response
    {
        $studentId = (int)$_SESSION['user']['id'];
        $taskId = (int)($req->get['task_id'] ?? 0);
        $challengeSlug = trim((string)($req->get['challenge_slug'] ?? ''));

        if ($taskId === 0 || $challengeSlug === '') {
            return $this->jsonError('task_id and challenge_slug are required', 400);
        }

        // Get task and verify it belongs to this student
        $task = $this->tasks->findById($taskId);
        if (!$task) {
            return $this->jsonError('Task not found', 404);
        }
        if ((int)$task['student_id'] !== $studentId) {
            return $this->jsonError('Unauthorized', 403);
        }

        // Verify task is active
        if ($task['status'] !== 'active') {
            return $this->jsonError('Task is not active', 400);
        }

        // Verify task hasn't expired
        if (strtotime($task['expires_at']) < time()) {
            return $this->jsonError('Task has expired', 400);
        }

        // Get challenge to verify slug matches
        $challenge = \CTF\Server\Database\Connection::fetchOne(
            'SELECT id, uuid, slug FROM challenges WHERE slug = :slug',
            [':slug' => $challengeSlug]
        );
        if (!$challenge) {
            return $this->jsonError('Challenge not found', 404);
        }
        if ((int)$task['challenge_id'] !== (int)$challenge['id']) {
            return $this->jsonError('Challenge mismatch', 400);
        }

        // Generate and return the flag
        $flag = \CTF\Server\Security\FlagGenerator::compute(
            $studentId,
            (string)$challenge['uuid'],
            (string)$task['uuid']
        );

        return $this->jsonOk(['flag' => $flag]);
    }
}
