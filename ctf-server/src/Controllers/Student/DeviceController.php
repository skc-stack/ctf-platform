<?php
declare(strict_types=1);

namespace CTF\Server\Controllers\Student;

use CTF\Server\Controllers\BaseController;
use CTF\Server\Http\Request;
use CTF\Server\Http\Response;
use CTF\Server\Repositories\DeviceRepository;
use CTF\Server\Services\ActivationCodeService;

/**
 * Student device management: list own devices, request activation codes.
 */
final class DeviceController extends BaseController
{
    public function __construct(
        private readonly DeviceRepository $devices = new DeviceRepository(),
        private readonly ActivationCodeService $activationCodes = new ActivationCodeService(),
    ) {}

    /**
     * GET /student/devices — show student's devices and activation code request.
     */
    public function index(Request $req): Response
    {
        $user = $_SESSION['user'];
        $studentId = (int)$user['id'];

        $devices = $this->devices->listActiveByUser($studentId);

        return $this->view($req, 'student/devices/index', [
            'title' => '我的裝置 — CTF LAB',
            'devices' => $devices,
        ]);
    }

    /**
     * POST /api/v1/student/devices/request-code — generate an activation code.
     * Returns JSON with the code.
     */
    public function requestCode(Request $req): Response
    {
        $user = $_SESSION['user'];
        $studentId = (int)$user['id'];

        try {
            $code = $this->activationCodes->generate($studentId);
            return $this->jsonOk(['activation_code' => $code]);
        } catch (\InvalidArgumentException $e) {
            return $this->jsonError($e->getMessage(), 400);
        }
    }

    /**
     * POST /api/v1/student/devices/revoke — revoke one of student's own devices.
     */
    public function revoke(Request $req, string $id): Response
    {
        $user = $_SESSION['user'];
        $studentId = (int)$user['id'];
        $deviceId = (int)$id;

        $device = $this->devices->findById($deviceId);
        if (!$device || (int)$device['user_id'] !== $studentId) {
            return $this->jsonError('找不到該裝置', 404);
        }

        $this->devices->updateStatus($deviceId, DeviceRepository::STATUS_REVOKED);
        return $this->jsonOk(['message' => '已撤銷裝置']);
    }
}
