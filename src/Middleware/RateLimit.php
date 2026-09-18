<?php
declare(strict_types=1);

namespace CTF\Server\Middleware;

use CTF\Server\Database\Connection;
use CTF\Server\Http\Request;
use CTF\Server\Http\Response;

/**
 * Fixed-window rate limiter backed by the rate_limits table.
 *
 * Subclasses declare the bucket name and per-minute limit.
 * Uses bucket_key = "<bucket>:<ip>". Window resets when expires_at < NOW.
 *
 * Trade-off: not strictly atomic under high concurrency, but acceptable
 * for CTF lab use (attacker must already control a privileged account to
 * benefit from race-condition bypasses).
 */
abstract class RateLimit extends BaseMiddleware
{
    abstract protected function bucket(): string;
    abstract protected function perMinute(): int;

    public function handle(Request $req, callable $next): Response
    {
        $key = $this->bucket() . ':' . $req->ip();
        $limit = $this->perMinute();
        $count = $this->increment($key);

        if ($count > $limit) {
            if ($req->isJson() || str_starts_with($req->path, '/api/')) {
                return Response::json(['success' => false, 'error' => 'Too many requests'], 429);
            }
            return Response::make(
                '<!doctype html><meta charset=utf-8><title>429</title>'
                . '<h1 style="font-family:sans-serif;color:#F59E0B">429 Too Many Requests</h1>'
                . '<p>Please slow down.</p>',
                429,
                ['Content-Type' => 'text/html; charset=utf-8']
            );
        }

        return $next($req);
    }

    private function increment(string $key): int
    {
        $pdo = Connection::pdo();

        $row = Connection::fetchOne(
            'SELECT hit_count, expires_at FROM rate_limits WHERE bucket_key = :k',
            [':k' => $key]
        );

        if (!$row) {
            Connection::run(
                'INSERT INTO rate_limits (bucket_key, hit_count, window_started_at, expires_at)
                 VALUES (:k, 1, NOW(), NOW() + INTERVAL 60 SECOND)',
                [':k' => $key]
            );
            return 1;
        }

        $expired = strtotime((string)$row['expires_at']) < time();
        if ($expired) {
            Connection::run(
                'UPDATE rate_limits
                 SET hit_count = 1, window_started_at = NOW(), expires_at = NOW() + INTERVAL 60 SECOND
                 WHERE bucket_key = :k',
                [':k' => $key]
            );
            return 1;
        }

        Connection::run(
            'UPDATE rate_limits SET hit_count = hit_count + 1 WHERE bucket_key = :k',
            [':k' => $key]
        );
        return ((int)$row['hit_count']) + 1;
    }
}
