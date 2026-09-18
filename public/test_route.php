<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require '/var/www/html/ctf.kghs.kh.edu.tw/vendor/autoload.php';
require '/var/www/html/ctf.kghs.kh.edu.tw/bootstrap.php';

$_SESSION['user'] = ['id' => 2, 'username' => 'CtfAdmin', 'role' => 'admin', 'status' => 'active'];

// Simulate the request
$_SERVER['REQUEST_URI'] = '/admin/devices';
$_SERVER['REQUEST_METHOD'] = 'GET';

$req = new \CTF\Server\Http\Request();
$router = new \CTF\Server\Http\Router();

require '/var/www/html/ctf.kghs.kh.edu.tw/routes/web.php';
ctf_web_routes($router);

try {
    $response = $router->dispatch($req);
    echo "Response status: " . $response->status . "\n";
    echo "Response body (first 500 chars):\n" . substr($response->body, 0, 500) . "\n";
    if (strpos($response->body, '裝置管理') !== false) {
        echo "\nSUCCESS: 裝置管理 found!\n";
    } else {
        echo "\nFAIL: 裝置管理 NOT found!\n";
    }
} catch (Throwable $e) {
    echo "Exception: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . "\n";
    echo "Line: " . $e->getLine() . "\n";
    echo "Trace:\n" . $e->getTraceAsString() . "\n";
}
unlink(__FILE__);