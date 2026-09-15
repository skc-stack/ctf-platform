<?php
declare(strict_types=1);

namespace CTF\Server\Controllers;

use CTF\Server\Http\Request;
use CTF\Server\Http\Response;
use CTF\Server\Support\Config;

final class HomeController extends BaseController
{
    public function index(Request $req): Response
    {
        return $this->view($req, 'home', [
            'title' => 'CTF LAB — 資安攻防演練平台',
            'siteName' => Config::get('APP_NAME', 'CTF LAB'),
            'env' => Config::get('APP_ENV', 'unknown'),
        ]);
    }

    public function health(Request $req): Response
    {
        $checks = [
            'php' => PHP_VERSION,
            'app_key_set' => Config::get('APP_KEY') !== null,
            'flag_secret_set' => Config::get('FLAG_MASTER_SECRET') !== null,
            'db' => 'unknown',
        ];

        try {
            $stmt = \CTF\Server\Database\Connection::run('SELECT 1 AS ok');
            $checks['db'] = $stmt->fetch()['ok'] == 1 ? 'ok' : 'fail';
        } catch (\Throwable $e) {
            $checks['db'] = 'fail: ' . $e->getMessage();
        }

        $status = ($checks['db'] === 'ok' && $checks['app_key_set'] && $checks['flag_secret_set']) ? 200 : 503;
        return Response::json([
            'success' => $status === 200,
            'data' => $checks,
            'env' => Config::get('APP_ENV'),
        ], $status);
    }

    public function leaderboard(Request $req): Response
    {
        $rows = \CTF\Server\Database\Connection::fetchAll(
            'SELECT * FROM leaderboard ORDER BY score DESC, last_solve ASC LIMIT 100'
        );
        return $this->view($req, 'leaderboard', [
            'title' => 'Leaderboard — CTF LAB',
            'rows' => $rows,
        ]);
    }
}
