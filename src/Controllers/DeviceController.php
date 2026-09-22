<?php
declare(strict_types=1);

namespace CTF\Server\Controllers;

use CTF\Server\Database\Connection;
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
     * GET /api/v1/device/challenges
     *
     * Returns list of published challenges available for this device.
     * Agent uses this to decide which challenges to download.
     */
    public function listChallenges(Request $req): Response
    {
        $device = $req->device;

        // Get published challenges with their latest package info
        $challenges = Connection::fetchAll(
            'SELECT c.id, c.slug, c.version, c.title, cp.sha256
             FROM challenges c
             JOIN challenge_packages cp ON cp.challenge_id = c.id
             WHERE c.status = :status
               AND cp.version = c.version
             ORDER BY c.id ASC',
            [':status' => 'published']
        );

        $data = array_map(fn($row) => [
            'id' => (int)$row['id'],
            'challenge_id' => (string)$row['slug'],
            'version' => (int)$row['version'],
            'sha256' => (string)$row['sha256'],
            'title' => (string)$row['title'],
        ], $challenges);

        return $this->jsonOk(['challenges' => $data]);
    }

    /**
     * GET /api/v1/device/challenges/{id}/download
     *
     * Returns the challenge ZIP file as raw binary.
     */
    public function downloadChallenge(Request $req, string $id): Response
    {
        $device = $req->device;

        // Find the challenge package
        $pkg = Connection::fetchOne(
            'SELECT cp.file_path, cp.original_name, cp.file_size
             FROM challenge_packages cp
             JOIN challenges c ON c.id = cp.challenge_id
             WHERE cp.challenge_id = :cid AND cp.version = c.version
               AND c.status = :status
             LIMIT 1',
            [':cid' => (int)$id, ':status' => 'published']
        );

        if (!$pkg) {
            return $this->jsonError('Challenge not found', 404);
        }

        $filePath = $pkg['file_path'];
        if (!is_file($filePath) || !is_readable($filePath)) {
            return $this->jsonError('Challenge file not available', 404);
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            return $this->jsonError('Failed to read challenge file', 500);
        }

        return Response::make(
            $content,
            200,
            [
                'Content-Type' => 'application/zip',
                'Content-Disposition' => 'attachment; filename="' . basename($pkg['original_name'] ?? 'challenge.zip') . '"',
                'Content-Length' => (string)strlen($content),
            ]
        );
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

    /**
     * POST /api/v1/device/sync-report
     *
     * Agent reports what challenges it has synced/installed.
     * Body: { "challenges": [{ "challenge_id": "DEMO-001", "version": 1, "sha256": "..." }, ...] }
     */
    public function syncReport(Request $req): Response
    {
        $device = $req->device;
        $deviceId = (int)$device['id'];
        $challenges = $req->json()['challenges'] ?? [];

        if (!is_array($challenges)) {
            return $this->jsonError('Invalid challenges format', 400);
        }

        try {
            Connection::transaction(function () use ($deviceId, $challenges) {
                // Delete old sync records for this device
                Connection::run(
                    'DELETE FROM device_sync_status WHERE device_id = :did',
                    [':did' => $deviceId]
                );

                // Insert new records
                foreach ($challenges as $ch) {
                    if (empty($ch['challenge_id']) || empty($ch['version'])) {
                        continue;
                    }
                    Connection::run(
                        'INSERT INTO device_sync_status (device_id, challenge_id, challenge_version, sha256, synced_at)
                         VALUES (:did, :cid, :ver, :sha, NOW())
                         ON DUPLICATE KEY UPDATE challenge_version = :ver2, sha256 = :sha2, synced_at = NOW()',
                        [
                            ':did' => $deviceId,
                            ':cid' => (string)$ch['challenge_id'],
                            ':ver' => (int)$ch['version'],
                            ':sha' => (string)($ch['sha256'] ?? ''),
                            ':ver2' => (int)$ch['version'],
                            ':sha2' => (string)($ch['sha256'] ?? ''),
                        ]
                    );
                }
            });

            return $this->jsonOk(['synced' => count($challenges), 'synced_at' => date('Y-m-d H:i:s')]);
        } catch (\Throwable $e) {
            return $this->jsonError('Failed to save sync report: ' . $e->getMessage(), 500);
        }
    }
}