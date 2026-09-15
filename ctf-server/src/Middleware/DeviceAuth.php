<?php
declare(strict_types=1);

namespace CTF\Server\Middleware;

use CTF\Server\Database\Connection;
use CTF\Server\Http\Request;
use CTF\Server\Http\Response;
use CTF\Server\Repositories\DeviceRepository;

/**
 * Middleware: validates Device API requests via Bearer token.
 *
 * Checks:
 * 1. Authorization: Bearer <token> header present
 * 2. X-Device-ID: <uuid> header present
 * 3. Hash of <token> matches device_token_hash in DB
 * 4. Device exists and status = 'active'
 *
 * On success, sets $_SESSION['device'] with device row for controllers.
 */
final class DeviceAuth
{
    public function handle(Request $req, callable $next): Response
    {
        $authHeader = $req->header('Authorization');
        if ($authHeader === null || !str_starts_with($authHeader, 'Bearer ')) {
            return Response::json(['success' => false, 'error' => 'Unauthorized'], 401);
        }

        $token = substr($authHeader, 7);
        $deviceUuid = $req->header('X-Device-ID') ?? $req->header('X-Device-Id');

        if ($token === '' || $deviceUuid === null) {
            return Response::json(['success' => false, 'error' => 'Missing token or device ID'], 401);
        }

        $tokenHash = hash('sha256', $token);

        // Find device by token hash
        $device = Connection::fetchOne(
            'SELECT d.*, u.role AS user_role, u.username AS user_username
             FROM devices d
             JOIN users u ON u.id = d.user_id
             WHERE d.device_token_hash = :h AND d.uuid = :u',
            [':h' => $tokenHash, ':u' => $deviceUuid]
        );

        if ($device === null) {
            return Response::json(['success' => false, 'error' => 'Unauthorized'], 401);
        }

        if ($device['status'] !== DeviceRepository::STATUS_ACTIVE) {
            return Response::json(['success' => false, 'error' => 'Device revoked or disabled'], 401);
        }

        // Set device info on request for controller use
        $req->device = $device;

        return $next($req);
    }
}