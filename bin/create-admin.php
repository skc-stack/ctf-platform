<?php
declare(strict_types=1);

/**
 * bin/create-admin.php — Interactively create the first admin user.
 *
 * Usage:
 *   php bin/create-admin.php
 *
 * Reads username, email, display_name, password from STDIN (with hidden password).
 * After creation, logs audit row 'create_admin'.
 *
 * Safe to re-run: refuses if a username already exists.
 */

require __DIR__ . '/../bootstrap.php';

use CTF\Server\Database\Connection;
use CTF\Server\Repositories\UserRepository;
use CTF\Server\Security\PasswordHasher;
use CTF\Server\Services\AuditLog;

function prompt(string $label, bool $hidden = false): string
{
    fwrite(STDOUT, $label);
    if ($hidden && stripos(PHP_OS, 'WIN') === false) {
        // Best-effort hidden input on POSIX; on Windows, just read plainly.
        system('stty -echo');
        $line = (string)trim((string)fgets(STDIN));
        system('stty echo');
        fwrite(STDOUT, "\n");
        return $line;
    }
    return (string)trim((string)fgets(STDIN));
}

fwrite(STDOUT, "=== Create CTF Admin ===\n\n");

$username = prompt('Username (3-64, [A-Za-z0-9_.-]): ');
if (!preg_match('/^[A-Za-z0-9_.\-]{3,64}$/', $username)) {
    fwrite(STDERR, "Invalid username format.\n");
    exit(1);
}

$email = prompt('Email (optional, blank to skip): ');
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Invalid email format.\n");
    exit(1);
}

$displayName = prompt('Display name (1-100): ');
if ($displayName === '' || strlen($displayName) > 100) {
    fwrite(STDERR, "Invalid display name.\n");
    exit(1);
}

// Password: prompt twice and validate
$password = prompt('Password (>=8, mixed case + digit): ', true);
$passwordConfirm = prompt('Confirm password: ', true);

if ($password !== $passwordConfirm) {
    fwrite(STDERR, "Passwords do not match.\n");
    exit(1);
}
$pwErr = PasswordHasher::policyError($password);
if ($pwErr !== null) {
    fwrite(STDERR, "Password policy: {$pwErr}\n");
    exit(1);
}

// Refuse if username exists
$users = new UserRepository();
if ($users->findByUsername($username)) {
    fwrite(STDERR, "Username '{$username}' already exists.\n");
    exit(1);
}

// Create
$id = $users->create([
    'username' => $username,
    'email' => $email !== '' ? $email : null,
    'password' => $password,
    'display_name' => $displayName,
    'role' => UserRepository::ROLE_ADMIN,
    'status' => UserRepository::STATUS_ACTIVE,
]);

// Admin does NOT need to verify Email — they're trusted and would otherwise
// be locked out (no UI to re-send a verification email to themselves).
// Skip the email-verified gate by stamping verified_at on creation.
Connection::run('UPDATE users SET email_verified_at = NOW() WHERE id = :id', [':id' => $id]);

AuditLog::log('create_admin', $id, 'user', (string)$id, ['via' => 'cli']);

fwrite(STDOUT, "\n[ok] Admin '{$username}' created (id={$id}, email pre-verified). You may now log in.\n");
