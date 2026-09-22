<?php
declare(strict_types=1);

namespace CTF\Portal;

/**
 * Portal controllers. Methods return the array shape the Router expects.
 */
final class PortalController
{
    public function __construct(
        private readonly AgentClient $agent = new AgentClient(),
    ) {}

    /* ===== Home / Status ===== */

    public function home(array $req): array
    {
        $status = $this->agent->get('/status');
        return Router::render('home', [
            'title' => 'CTF Target Portal',
            'status' => $status,
            'sync_result' => $req['query']['sync'] ?? null,
            'reset_id' => $req['query']['reset'] ?? null,
            'reset_ok' => isset($req['query']['ok']) ? (string)$req['query']['ok'] : null,
        ]);
    }

    /* ===== Activate ===== */

    public function showActivate(array $req): array
    {
        return Router::render('activate', [
            'title' => '啟用裝置 — CTF Target Portal',
            'prefill' => '',
            'error' => null,
        ]);
    }

    public function doActivate(array $req): array
    {
        $code = trim((string)($req['post']['activation_code'] ?? ''));
        $deviceName = trim((string)($req['post']['device_name'] ?? ''));
        $deviceUuid = $this->vmUuid();
        if ($code === '') {
            return Router::render('activate', [
                'title' => '啟用裝置 — CTF Target Portal',
                'prefill' => $code,
                'error' => '請貼上啟用碼',
            ], 400);
        }
        $result = $this->agent->post('/activate', [
            'activation_code' => $code,
            'device_uuid' => $deviceUuid,
            'device_name' => $deviceName !== '' ? $deviceName : (gethostname() ?: 'ctf-target'),
        ]);
        if ($result['ok']) {
            return [
                'status' => 302,
                'headers' => ['Location' => '/', 'Content-Type' => 'text/html; charset=utf-8'],
                'body' => '',
            ];
        }
        $http = $result['status'] ?: 400;
        return Router::render('activate', [
            'title' => '啟用裝置 — CTF Target Portal',
            'prefill' => $code,
            'error' => $result['error'] ?? "啟用失敗 (HTTP $http)",
        ], $http);
    }

    /* ===== Sync (manual trigger) ===== */

    public function doSync(array $req): array
    {
        $result = $this->agent->post('/sync');
        return [
            'status' => 302,
            'headers' => [
                'Location' => '/?sync=' . ($result['ok'] ? '1' : '0'),
                'Content-Type' => 'text/html; charset=utf-8',
            ],
            'body' => '',
        ];
    }

    /* ===== Task ===== */

    public function showTask(array $req): array
    {
        return Router::render('task', [
            'title' => '開始挑戰 — CTF Target Portal',
            'prefill' => '',
            'result' => null,
            'error' => null,
        ]);
    }

    public function doTask(array $req): array
    {
        $token = trim((string)($req['post']['task_token'] ?? ''));
        if ($token === '') {
            return Router::render('task', [
                'title' => '開始挑戰 — CTF Target Portal',
                'prefill' => '',
                'result' => null,
                'error' => '請貼上 Task Token',
            ], 400);
        }
        $result = $this->agent->post('/task', ['task_token' => $token]);
        $body = $result['body'];
        if ($result['ok']) {
            return Router::render('task', [
                'title' => '開始挑戰 — CTF Target Portal',
                'prefill' => $token,
                'result' => $body['data'] ?? $body,
                'error' => null,
            ]);
        }
        return Router::render('task', [
            'title' => '開始挑戰 — CTF Target Portal',
            'prefill' => $token,
            'result' => null,
            'error' => $result['error'] ?? '驗證失敗',
        ], $result['status'] ?: 400);
    }

    /* ===== Reset ===== */

    public function doReset(array $req): array
    {
        $challengeId = trim((string)($req['post']['challenge_id'] ?? ''));
        if ($challengeId === '') {
            return [
                'status' => 302,
                'headers' => ['Location' => '/', 'Content-Type' => 'text/html; charset=utf-8'],
                'body' => '',
            ];
        }
        $result = $this->agent->post('/reset', ['challenge_id' => $challengeId]);
        return [
            'status' => 302,
            'headers' => [
                'Location' => '/?reset=' . urlencode($challengeId) . '&ok=' . ($result['ok'] ? '1' : '0'),
                'Content-Type' => 'text/html; charset=utf-8',
            ],
            'body' => '',
        ];
    }

    /* ===== Get Flag ===== */

    /**
     * POST /getflag
     * Called by challenge PHP when check() returns true.
     * Proxies to Agent which calls Server for the dynamic flag.
     */
    public function doGetFlag(array $req): array
    {
        $taskId = (int)($req['post']['task_id'] ?? 0);
        $challengeSlug = trim((string)($req['post']['challenge_slug'] ?? ''));

        if ($taskId === 0 || $challengeSlug === '') {
            return [
                'status' => 400,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => json_encode(['error' => 'task_id and challenge_slug required']),
            ];
        }

        $result = $this->agent->post('/getflag', [
            'task_id' => $taskId,
            'challenge_slug' => $challengeSlug,
        ]);

        if ($result['ok']) {
            return [
                'status' => 200,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => json_encode(['flag' => $result['body']['flag'] ?? '']),
            ];
        }

        return [
            'status' => $result['status'] ?: 500,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode(['error' => $result['error'] ?? 'Failed to get flag']),
        ];
    }

    /* ===== Challenge Start ===== */

    /**
     * GET /challenge/start/{slug}?task_id=XXX
     *
     * Called when student enters a challenge. Records start time via Server API,
     * then redirects to the actual challenge page.
     */
    public function challengeStart(array $req): array
    {
        $slug = $req['route_params'][0] ?? '';
        $taskId = (int)($req['query']['task_id'] ?? 0);

        if ($slug === '' || $taskId === 0) {
            return [
                'status' => 302,
                'headers' => ['Location' => '/task', 'Content-Type' => 'text/html; charset=utf-8'],
                'body' => '',
            ];
        }

        // Tell the server the student has started the challenge
        // (accumulates time if returning)
        $this->agent->post('/challenge-start', ['task_id' => $taskId]);

        // Redirect to the actual challenge directory
        // Use /enter/ prefix so Apache doesn't intercept it as a static directory
        // Pass task_id as query param so challenge PHP can use it for getflag()
        return [
            'status' => 302,
            'headers' => [
                'Location' => '/enter/' . $slug . '/?task_id=' . $taskId,
                'Content-Type' => 'text/html; charset=utf-8',
            ],
            'body' => '',
        ];
    }

    /**
     * GET /enter/{slug}
     * Serve the challenge's index.php from the challenge directory.
     */
    public function serveChallenge(array $req): array
    {
        $slug = $req['route_params'][0] ?? '';
        $challengePath = '/srv/ctf/challenges/' . $slug;
        $indexFile = $challengePath . '/index.php';

        if (!is_file($indexFile)) {
            return [
                'status' => 404,
                'headers' => ['Content-Type' => 'text/html; charset=utf-8'],
                'body' => '<h1>404 - Challenge not found</h1>',
            ];
        }

        // Include and execute the challenge's index.php
        // The challenge's PHP can access $slug via $_GET['slug']
        chdir($challengePath);
        ob_start();
        try {
            include $indexFile;
            $body = ob_get_clean();
        } catch (\Throwable $e) {
            $body = '<h1>Error loading challenge</h1><pre>' . htmlspecialchars($e->getMessage()) . '</pre>';
        }
        return [
            'status' => 200,
            'headers' => ['Content-Type' => 'text/html; charset=utf-8'],
            'body' => $body,
        ];
    }

    /* ===== Helpers ===== */

    private function vmUuid(): string
    {
        $host = gethostname() ?: 'unknown';
        $mid = '';
        if (is_readable('/etc/machine-id')) {
            $mid = trim((string)@file_get_contents('/etc/machine-id'));
        }
        $seed = $mid !== '' ? ($mid . '|' . $host) : $host;
        $h = hash('sha256', $seed);
        return sprintf(
            '%08s-%04s-%04s-%04s-%12s',
            substr($h, 0, 8), substr($h, 8, 4),
            substr($h, 12, 4), substr($h, 16, 4),
            substr($h, 20, 12)
        );
    }
}
