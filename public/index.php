<?php
declare(strict_types=1);

/**
 * Front controller for CTF Server.
 * Apache rewrites all non-asset requests to this file via .htaccess.
 */

require __DIR__ . '/../bootstrap.php';

use CTF\Server\Http\Request;
use CTF\Server\Http\Router;
use CTF\Server\Http\Response;
use CTF\Server\Support\Logger;

try {
    $request = new Request();
    $router = new Router();

    require __DIR__ . '/../routes/web.php';
    ctf_web_routes($router);

    // API routes will be added in Phase 3
    $apiFile = __DIR__ . '/../routes/api.php';
    if (is_file($apiFile)) {
        require $apiFile;
    }

    $response = $router->dispatch($request);
    $response->send();
} catch (\Throwable $e) {
    Logger::get()->critical('Front controller crash', [
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
    }
    $debug = (string)($_ENV['APP_DEBUG'] ?? 'false');
    if ($debug === 'true' || $debug === '1') {
        echo "Fatal: " . $e->getMessage() . "\n\n" . $e->getTraceAsString();
    } else {
        echo '500 Internal Server Error';
    }
}
