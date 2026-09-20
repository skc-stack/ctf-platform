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
        // The plain token is only available when the task is first created.
        // If no token is passed, the student needs to start a new task.
        return $this->view($req, 'student/task/show', [
            'title' => 'Task Token — CTF LAB',
            'task' => $task,
            'task_token' => null,
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
        return $this->jsonOk($result);
    }

    /**
     * GET /api/v1/student/task/{id}/status
     * Returns current task status for polling.
     */
    public function statusApi(Request $req, string $id): Response
    {
        $studentId = (int)$_SESSION['user']['id'];
        $task = $this->tasks->findById((int)$id);
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
}
