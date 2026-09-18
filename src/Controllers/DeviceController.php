<?php
declare(strict_types=1);

namespace CTF\Server\Controllers;

use CTF\Server\Http\Request;
use CTF\Server\Http\Response;
use CTF\Server\Services\DeviceService;

/**
 * Device API endpoints (used by Target VM agents).
 */
final class DeviceController extends BaseController
{
    public function __construct(
        private readonly DeviceService $service = new DeviceService(),
    ) {}

    /**
     * POST /api/v1/device/info
     */
    public function info(Request $req): Response
    {
        $device = $req->device;
        return $this->jsonOk([
            'device_id' => $device['id'],
            'device_uuid' => $device['uuid'],
            'name' => $device['name'],
            'status' => $device['status'],
            'last_seen_at' => $device['last_seen_at'],
            'last_ip' => $device['last_ip'],
            'agent_version' => $device['agent_version'],
            'target_version' => $device['target_version'],
            'owner' => [
                'user_id' => $device['user_id'],
                'username' => $device['user_username'],
                'role' => $device['user_role'],
            ],
        ]);
    }

    /**
     * POST /api/v1/device/heartbeat
     */
    public function heartbeat(Request $req): Response
    {
        $device = $req->device;
        $agentVersion = $req->post['agent_version'] ?? $req->json()['agent_version'] ?? null;
        $targetVersion = $req->post['target_version'] ?? $req->json()['target_version'] ?? null;

        $ok = $this->service->heartbeat(
            (int)$device['id'],
            $req->ip(),
            is_string($agentVersion) ? substr($agentVersion, 0, 50) : null,
            is_string($targetVersion) ? substr($targetVersion, 0, 50) : null,
        );

        if ($ok) {
            return $this->jsonOk(['last_seen_at' => date('Y-m-d H:i:s')]);
        }
        return $this->jsonError('Failed to update heartbeat', 500);
    }
}