<?php
declare(strict_types=1);

/**
 * Target Portal — front controller.
 *
 * Apache rewrites all non-asset requests here. We:
 *   1. Sanitize the request into a small array.
 *   2. Dispatch through the Router (no framework).
 *   3. Emit headers + body.
 *
 * Security:
 *   - Apache config disables shell_exec/system/exec/etc. via php.ini.
 *   - This file should never call any of those functions; grep guards
 *     in tests enforce it.
 */

require __DIR__ . '/../src/Router.php';
require __DIR__ . '/../src/View.php';
require __DIR__ . '/../src/AgentClient.php';
require __DIR__ . '/../src/PortalController.php';

use CTF\Portal\Router;
use CTF\Portal\PortalController;

$remote = $_SERVER['REMOTE_ADDR'] ?? '';

$req = [
    'method' => strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
    'path'   => parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/',
    'query'  => $_GET,
    'post'   => $_POST,
    'ip'     => $remote,
];

$router = new Router();

// Public routes — no auth.
$router->get('/', [], [PortalController::class, 'home']);
$router->get('/activate', [], [PortalController::class, 'showActivate']);
$router->post('/activate', [], [PortalController::class, 'doActivate']);
$router->post('/sync', [], [PortalController::class, 'doSync']);
$router->get('/task', [], [PortalController::class, 'showTask']);
$router->post('/task', [], [PortalController::class, 'doTask']);
$router->post('/reset', [], [PortalController::class, 'doReset']);
// Challenge entrypoint — record start time then redirect to challenge
$router->get('/challenge/start/{slug}', [], [PortalController::class, 'challengeStart']);
// Serve challenge page (after redirect from challengeStart)
$router->get('/enter/{slug}', [], [PortalController::class, 'serveChallenge']);

$result = $router->dispatch($req);

http_response_code($result['status']);
foreach ($result['headers'] as $name => $value) {
    header("$name: $value");
}
echo $result['body'];
