<?php
declare(strict_types=1);

namespace CTF\Server\Controllers;

use CTF\Server\Http\Request;
use CTF\Server\Http\Response;
use CTF\Server\Repositories\ActivationCodeRepository;
use CTF\Server\Services\ActivationCodeService;
use CTF\Server\Services\DeviceService;

/**
 * Device activation API (called by Target VM).
 */
final class DeviceApiController extends BaseController
{
    public function __construct(
        private readonly ActivationCodeService $activationCodeService = new ActivationCodeService(),
        private readonly DeviceService $deviceService = new DeviceService(),
    ) {}

    /**
     * POST /api/v1/device/activate
     *
     * Body (JSON or form):
     *   - activation_code (required)
     *   - device_uuid (required)
     *   - device_name (required)
     *
     * Returns:
     *   - device_id (int)
     *   - device_token (string, only shown once)
     */
    public function activate(Request $req): Response
    {
        $data = $req->isJson() ? $req->json() : $req->post;

        $activationCode = (string)($data['activation_code'] ?? '');
        $deviceUuid = (string)($data['device_uuid'] ?? '');
        $deviceName = (string)($data['device_name'] ?? '');

        if ($activationCode === '' || $deviceUuid === '' || $deviceName === '') {
            return $this->jsonError('Missing required fields: activation_code, device_uuid, device_name', 400);
        }

        // Validate UUID format
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $deviceUuid)) {
            return $this->jsonError('Invalid device_uuid format', 400);
        }

        if (strlen($deviceName) > 120) {
            return $this->jsonError('device_name too long (max 120 chars)', 400);
        }

        try {
            $result = $this->deviceService->activateDevice(
                $this->activationCodeService,
                $activationCode,
                $deviceUuid,
                $deviceName,
                $req->ip(),
            );

            return $this->jsonOk([
                'device_id' => $result['device_id'],
                'device_uuid' => $result['device_uuid'],
                'device_token' => $result['device_token'],
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->jsonError($e->getMessage(), 400);
        }
    }
}