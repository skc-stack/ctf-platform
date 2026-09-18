<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require '/var/www/html/ctf.kghs.kh.edu.tw/vendor/autoload.php';

$router = new \CTF\Server\Http\Router();

// Manually add the route
use CTF\Server\Controllers\Admin\DeviceController as AdminDeviceController;
use CTF\Server\Middleware\Auth;
use CTF\Server\Middleware\RequireAdmin;

$router->get('/admin/devices', [Auth::class, RequireAdmin::class], [AdminDeviceController::class, 'index']);

// Check the routes registered
$refl = new ReflectionClass($router);
$prop = $refl->getProperty('routes');
$prop->setAccessible(true);
$routes = $prop->getValue($router);

echo "Registered routes:\n";
foreach ($routes as $route) {
    echo "  Method: " . $route['method'] . ", Pattern: " . $route['pattern'] . ", Regex: " . $route['regex'] . "\n";
}

// Simulate dispatch
$_SERVER['REQUEST_URI'] = '/admin/devices';
$_SERVER['REQUEST_METHOD'] = 'GET';

$req = new \CTF\Server\Http\Request();
echo "\nRequest path: " . $req->path . "\n";
echo "Request method: " . $req->method . "\n";

// Try to match the route manually
foreach ($routes as $route) {
    if ($route['method'] !== $req->method) {
        echo "Skipping route (method mismatch): " . $route['pattern'] . "\n";
        continue;
    }
    $candidate = rtrim($req->path, '/') ?: '/';
    $match = preg_match($route['regex'], $candidate, $m);
    echo "Testing route: " . $route['pattern'] . " (regex: " . $route['regex'] . ") => " . ($match ? "MATCH" : "NO MATCH") . "\n";
    if ($match) {
        echo "  Captured: " . print_r($m, true) . "\n";
    }
}

unlink(__FILE__);