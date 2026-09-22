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
            $this->flashSuccess('Task Token 已建立，請在 ' . (int)(\CTF\Server\Support\Config::get('DEFAULT_TASK_TTL', 120)) . ' 分鐘內貼到 Target Portal');
            return Response::redirect('/student/task/' . (int)$result['task']['id']);
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
        // The token is not stored; we already gave it on start. Show only metadata.
        return $this->view($req, 'student/task/show', [
            'title' => 'Task Token — CTF LAB',
            'task' => $task,
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
            return $this->jsonOk([
                'task_id' => (int)$result['task']['id'],
                'task_uuid' => (string)$result['task']['uuid'],
                'challenge_id' => (int)$result['challenge']['id'],
                'challenge_uuid' => (string)$result['challenge']['uuid'],
                'entrypoint' => '/challenge/' . (string)$result['challenge']['slug'] . '/',
                'challenge_version' => (int)$result['challenge']['version'],
                'expires_at' => (string)$result['task']['expires_at'],
            ]);
        } catch (\CTF\Server\Services\TaskValidationException $e) {
            return $this->jsonError($e->getMessage(), $e->httpStatus, ['code' => $e->errorCode]);
        }
    }
}
