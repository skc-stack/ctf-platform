<?php
declare(strict_types=1);

namespace CTF\Server\Controllers\Admin;

use CTF\Server\Controllers\BaseController;
use CTF\Server\Http\Request;
use CTF\Server\Http\Response;
use CTF\Server\Repositories\DeviceRepository;
use CTF\Server\Services\AuditLog;

final class DeviceController extends BaseController
{
    public function __construct(
        private readonly DeviceRepository $devices = new DeviceRepository(),
    ) {}

    public function index(Request $req): Response
    {
        $page = max(1, (int)($req->get['page'] ?? 1));
        $perPage = 20;
        $data = $this->devices->listAll($page, $perPage);

        $codes = $this->devices->listActivationCodes();

        return $this->view($req, 'admin/devices', [
            'title'   => '裝置管理 — CTF LAB',
            'devices' => $data['rows'],
            'pagination' => [
                'page'    => $page,
                'total'   => $data['total'],
                'perPage' => $perPage,
                'pages'   => (int)ceil($data['total'] / $perPage),
            ],
            'activation_codes' => $codes,
        ]);
    }

    /**
     * GET /admin/devices/{id}/sync
     * View synced challenges for a device (AJAX).
     */
    public function syncStatus(Request $req, string $id): Response
    {
        $deviceId = (int)$id;
        $device = $this->devices->findById($deviceId);
        if (!$device) {
            return $this->jsonError('Device not found', 404);
        }
        $syncedChallenges = $this->devices->getSyncedChallenges($deviceId);
        return $this->jsonOk([
            'device' => [
                'id' => $device['id'],
                'uuid' => $device['uuid'],
                'name' => $device['name'],
            ],
            'challenges' => $syncedChallenges,
        ]);
    }

    public function revoke(Request $req, string $id): Response
    {
        $deviceId = (int)$id;
        $device = $this->devices->findById($deviceId);
        if (!$device) {
            $this->flashError('找不到該裝置');
            return Response::redirect('/admin/devices');
        }
        $this->devices->updateStatus($deviceId, DeviceRepository::STATUS_REVOKED);
        AuditLog::log('device_revoke_admin', (int)($_SESSION['user']['id'] ?? 0), 'device', (string)$deviceId, [
            'device_uuid' => $device['uuid'],
            'owner' => $device['user_username'],
        ]);
        $this->flashSuccess('已撤銷裝置');
        return Response::redirect('/admin/devices');
    }

    public function deleteActivationCode(Request $req, string $id): Response
    {
        $codeId = (int)$id;
        $deleted = $this->devices->deleteActivationCode($codeId);
        if (!$deleted) {
            $this->flashError('找不到該 Activation Code');
            return Response::redirect('/admin/devices');
        }
        $this->flashSuccess('已刪除 Activation Code');
        return Response::redirect('/admin/devices');
    }
}
