<?php
declare(strict_types=1);

namespace CTF\Server\Services;

use CTF\Server\Database\Connection;
use CTF\Server\Http\Request;

/**
 * Audit logger. Records security-relevant events to audit_logs.
 *
 * Use AuditLog::log() directly for simple cases, or AuditLog::fromRequest()
 * which auto-fills user_id / ip / user_agent from the current session + Request.
 */
final class AuditLog
{
    public static function log(
        string $action,
        ?int $userId = null,
        ?string $targetType = null,
        ?string $targetId = null,
        ?array $metadata = null,
        ?string $ip = null,
        ?string $userAgent = null,
    ): void {
        Connection::run(
            'INSERT INTO audit_logs
                (user_id, action, target_type, target_id, ip, user_agent, metadata_json, created_at)
             VALUES
                (:user_id, :action, :target_type, :target_id, :ip, :user_agent, :metadata, NOW())',
            [
                ':user_id' => $userId,
                ':action' => $action,
                ':target_type' => $targetType,
                ':target_id' => $targetId,
                ':ip' => $ip,
                ':user_agent' => $userAgent !== null ? substr($userAgent, 0, 500) : null,
                ':metadata' => $metadata !== null ? json_encode($metadata, JSON_UNESCAPED_UNICODE) : null,
            ]
        );
    }

    public static function fromRequest(
        Request $req,
        string $action,
        ?string $targetType = null,
        ?string $targetId = null,
        ?array $metadata = null,
        ?int $overrideUserId = null,
    ): void {
        $userId = $overrideUserId ?? ($_SESSION['user']['id'] ?? null);
        self::log(
            $action,
            $userId !== null ? (int)$userId : null,
            $targetType,
            $targetId,
            $metadata,
            $req->ip(),
            $req->userAgent(),
        );
    }

    public static function recent(int $limit = 50): array
    {
        $limit = max(1, min(500, $limit));
        return Connection::fetchAll(
            "SELECT a.*, u.username
             FROM audit_logs a
             LEFT JOIN users u ON u.id = a.user_id
             ORDER BY a.id DESC
             LIMIT {$limit}"
        );
    }
}
