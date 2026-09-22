<?php
declare(strict_types=1);

namespace CTF\Server\Controllers;

use CTF\Server\Http\Request;
use CTF\Server\Http\Response;
use CTF\Server\Repositories\TaskSessionRepository;
use CTF\Server\Services\SubmissionService;

/**
 * Student flag submission endpoint.
 *
 * Routes:
 *   POST /api/v1/student/submit        — browser, CSRF, Auth, RequireStudent
 *   POST /api/v1/device/task/complete  — device, DeviceAuth + RateLimit
 */
final class SubmissionController extends BaseController
{
    public function __construct(
        private readonly SubmissionService $service = new SubmissionService(),
        private readonly TaskSessionRepository $tasks = new TaskSessionRepository(),
    ) {}

    public function submitFromBrowser(Request $req): Response
    {
        $studentId = (int)$_SESSION['user']['id'];
        $taskId = (int)($req->post['task_id'] ?? 0);
        $flag = trim((string)($req->post['flag'] ?? ''));
        if ($taskId === 0 || $flag === '') {
            $this->flashError('請提供 task_id 與 flag');
            return Response::redirect('/student');
        }
        $result = $this->service->submitFromBrowser($studentId, $taskId, $flag, $req);
        if ($result['ok'] && $result['new_solve']) {
            $this->flashSuccess("答對了！+{$result['points']} 分（總分 {$result['total_score']}）");
        } elseif ($result['ok']) {
            $this->flashSuccess('Flag 正確，但你之前已解過此題（不再加分）');
        } else {
            $this->flashError("Flag 不正確（{$result['reason']}）");
        }
        return Response::redirect('/student/task/' . $taskId);
    }

    public function completeFromDevice(Request $req): Response
    {
        $device = $req->device ?? null;
        if ($device === null) {
            return $this->jsonError('Device not authenticated', 401);
        }
        $payload = $req->isJson() ? $req->json() : $req->post;
        $taskId = (int)($payload['task_id'] ?? 0);
        $flag = (string)($payload['flag'] ?? '');
        $nonce = (string)($payload['nonce'] ?? '');
        if ($taskId === 0 || $flag === '' || $nonce === '') {
            return $this->jsonError('Missing required fields: task_id, flag, nonce', 400);
        }
        if (strlen($nonce) < 8 || strlen($nonce) > 128) {
            return $this->jsonError('Invalid nonce length', 400);
        }
        $result = $this->service->completeFromDevice($device, $taskId, $flag, $nonce, $req);
        $http = 200;
        if (!$result['ok']) {
            $http = match ($result['reason']) {
                'nonce_replayed' => 409,
                'not_owner' => 403,
                'device_mismatch' => 403,
                default => 400,
            };
        }
        return $this->jsonOk([
            'correct' => $result['ok'],
            'reason' => $result['reason'],
            'points_awarded' => $result['points'],
            'total_score' => $result['total_score'],
            'new_solve' => $result['new_solve'],
        ], $http);
    }

    /**
     * Device flag submission without nonce — used by check_task.php on Target VM.
     * No nonce required because the device is already authenticated via Bearer token.
     */
    public function submitFlagFromDevice(Request $req): Response
    {
        $device = $req->device ?? null;
        if ($device === null) {
            return $this->jsonError('Device not authenticated', 401);
        }
        $payload = $req->isJson() ? $req->json() : $req->post;
        $taskId = (int)($payload['task_id'] ?? 0);
        $flag = (string)($payload['flag'] ?? '');
        if ($taskId === 0 || $flag === '') {
            return $this->jsonError('Missing required fields: task_id, flag', 400);
        }
        $result = $this->service->submitFromDeviceNoNonce($device, $taskId, $flag, $req);
        $http = 200;
        if (!$result['ok']) {
            $http = match ($result['reason']) {
                'task_not_found' => 404,
                'device_mismatch' => 403,
                default => 400,
            };
        }
        return $this->jsonOk([
            'correct' => $result['ok'],
            'reason' => $result['reason'],
            'points_awarded' => $result['points'],
            'total_score' => $result['total_score'],
            'new_solve' => $result['new_solve'],
        ], $http);
    }
}
