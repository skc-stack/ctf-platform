<?php
declare(strict_types=1);

/**
 * bin/cleanup.php — Daily cleanup of expired tokens and rate-limit buckets.
 *
 * Run from /etc/cron.daily/ctf-server-cleanup on the CTF Server.
 */

require __DIR__ . '/../bootstrap.php';

use CTF\Server\Database\Connection;
use CTF\Server\Support\Logger;

$logger = Logger::get();
$logger->info('cleanup.start');

// Expired email verification tokens
$rows1 = Connection::run(
    'DELETE FROM email_verification_tokens WHERE expires_at < NOW()'
)->rowCount();
$logger->info('cleanup.email_verification_tokens', ['deleted' => $rows1]);

// Expired password reset tokens
$rows2 = Connection::run(
    'DELETE FROM password_reset_tokens WHERE expires_at < NOW()'
)->rowCount();
$logger->info('cleanup.password_reset_tokens', ['deleted' => $rows2]);

// Rate-limit buckets that expired
$rows3 = Connection::run(
    'DELETE FROM rate_limits WHERE expires_at < NOW()'
)->rowCount();
$logger->info('cleanup.rate_limits', ['deleted' => $rows3]);

// Task sessions past their TTL
$rows_tasks = Connection::run(
    "UPDATE task_sessions
     SET status = 'expired'
     WHERE status = 'active' AND expires_at < NOW()"
)->rowCount();
$logger->info('cleanup.task_sessions_expired', ['marked_expired' => $rows_tasks]);

// Used tokens older than 7 days (audit trail purge, keep recent)
$rows4 = Connection::run(
    'DELETE FROM email_verification_tokens WHERE used_at IS NOT NULL AND used_at < (NOW() - INTERVAL 7 DAY)'
)->rowCount();
$rows5 = Connection::run(
    'DELETE FROM password_reset_tokens WHERE used_at IS NOT NULL AND used_at < (NOW() - INTERVAL 7 DAY)'
)->rowCount();
$logger->info('cleanup.used_tokens', ['verification_deleted' => $rows4, 'reset_deleted' => $rows5]);

$logger->info('cleanup.done', [
    'email_verification_deleted' => $rows1,
    'password_reset_deleted'    => $rows2,
    'rate_limits_deleted'       => $rows3,
    'task_sessions_expired'     => $rows_tasks,
    'used_verification_deleted' => $rows4,
    'used_reset_deleted'        => $rows5,
]);

echo "cleanup done. ev={$rows1} pr={$rows2} rl={$rows3} tasks_expired={$rows_tasks} used_ev={$rows4} used_pr={$rows5}\n";
