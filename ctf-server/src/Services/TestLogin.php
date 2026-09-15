<?php
require __DIR__ . '/../../bootstrap.php';
use CTF\Server\Services\AuthService;
use CTF\Server\Database\Connection;

// Check admin row
$row = Connection::fetchOne('SELECT id, username, status, email_verified_at FROM users WHERE username = ?', ['admin']);
echo "Admin row:\n";
print_r($row);
echo "\n";

// Test attemptLogin
$auth = new AuthService();
$user = $auth->attemptLogin('admin', 'Admin1234');
if ($user) {
    echo "Login succeeded.\n";
} else {
    echo "Login FAILED.\n";
    // Debug why
    $dbg = Connection::fetchOne(
        'SELECT username, status, email_verified_at, password_hash FROM users WHERE username = ?',
        ['admin']
    );
    echo "Debug row:\n";
    print_r($dbg);
}
