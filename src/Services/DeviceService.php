<?php
declare(strict_types=1);

namespace CTF\Server\Services;

use CTF\Server\Database\Connection;
use CTF\Server\Repositories\DeviceRepository;
use CTF\Server\Repositories\UserRepository;
use CTF\Server\Security\PasswordHasher;

/**
 * High-level device management: activate, heartbeat, revoke, list.
 */
final class DeviceService
{
    public function __construct(
        private readonly DeviceRepository $devices = new DeviceRepository(),
        private readonly UserRepository $users = new UserRepository(),
    ) {}

    /**
     * Activate a new device using an activation code.
     *
     * Returns the plain-text device token (only shown once).
     * @return array{device_id: int, device_token: string}
     */
    public function activateDevice(ActivationCodeService $activationCodeService, string $activationCode, string $deviceUuid, string $deviceName, string $ip): array
    {
        // Verify activation code
        $user = $activationCodeService->verifyAndUse($activationCode);
        if ($user === null) {
            throw new \InvalidArgumentException('Activation code is invalid, expired, or already used');
        }

        if ($user['role'] !== UserRepository::ROLE_STUDENT) {
            throw new \InvalidArgumentException('Only students can activate devices');
        }

        // Check device count limit
        $maxDevices = (int)\CTF\Server\Support\Config::get('MAX_DEVICES_PER_STUDENT', 3);
        $activeDevices = Connection::fetchAll(
            'SELECT COUNT(*) AS c FROM devices WHERE user_id = :u AND status = :s',
            [':u' => $user['id'], ':s' => DeviceRepository::STATUS_ACTIVE]
        );
        if ((int)($activeDevices[0]['c'] ?? 0) >= $maxDevices) {
            throw new \InvalidArgumentException("Cannot activate more than {$maxDevices} devices");
        }

        // Check if device UUID already exists under a DIFFERENT user
        // If so, revoke the old device record so this new user can claim the VM
        $existingDevice = $this->devices->findByUuid($deviceUuid);
        if ($existingDevice !== null && (int)$existingDevice['user_id'] !== (int)$user['id']) {
            // Revoke old device so new user can claim this VM
            $this->devices->updateStatus((int)$existingDevice['id'], DeviceRepository::STATUS_REVOKED);
        }

        // Generate device token (cryptographically random)
        $deviceToken = bin2hex(random_bytes(32));
        $deviceTokenHash = hash('sha256', $deviceToken);

        // Create device record
        $uuid = sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            random_int(0, 0xffff), random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff),
        );

        $deviceId = $this->devices->create([
            'uuid' => $uuid,
            'user_id' => $user['id'],
            'name' => $deviceName,
            'device_token_hash' => $deviceTokenHash,
            'last_ip' => $ip,
        ]);

        AuditLog::log('device_activate', (int)$user['id'], 'device', (string)$deviceId, [
            'device_uuid' => $uuid,
            'device_name' => $deviceName,
        ]);

        return [
            'device_id' => (int)$deviceId,
            'device_uuid' => $uuid,
            'device_token' => $deviceToken,
        ];
    }

    /**
     * Heartbeat: update last_seen_at.
     */
    public function heartbeat(int $deviceId, string $ip, string $agentVersion = null, string $targetVersion = null): bool
    {
        return $this->devices->touchLastSeen($deviceId, $ip, $agentVersion, $targetVersion);
    }

    /**
     * Get device info.
     * @return array<string,mixed>|null
     */
    public function getDeviceInfo(int $deviceId): ?array
    {
        return $this->devices->findById($deviceId);
    }

    /**
     * Revoke a device by owner.
     */
    public function revokeDevice(int $deviceId, int $ownerUserId): bool
    {
        $device = $this->devices->findById($deviceId);
        if (!$device || (int)$device['user_id'] !== $ownerUserId) {
            throw new \InvalidArgumentException('Device not found or unauthorized');
        }

        $ok = $this->devices->revoke($deviceId);
        if ($ok) {
            AuditLog::log('device_revoke', $ownerUserId, 'device', (string)$deviceId);
        }
        return $ok;
    }
}