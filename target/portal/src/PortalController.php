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

    /* ===== Challenge Entry ===== */

    /**
     * Start a challenge - validates task token and redirects to challenge
     */
    public function challengeStart(array $req): array
    {
        $slug = $req['params']['slug'] ?? '';

        if ($slug === '') {
            return [
                'status' => 302,
                'headers' => ['Location' => '/', 'Content-Type' => 'text/html; charset=utf-8'],
                'body' => '',
            ];
        }

        // Verify task token with Agent
        $taskToken = $_GET['token'] ?? $_POST['token'] ?? '';
        if ($taskToken === '') {
            return Router::render('error', [
                'title' => '錯誤',
                'message' => '缺少 Task Token',
            ], 400);
        }

        $result = $this->agent->post('/task', ['task_token' => $taskToken]);
        if (!$result['ok']) {
            return Router::render('error', [
                'title' => '錯誤',
                'message' => $result['error'] ?? 'Task Token 無效',
            ], 403);
        }

        // Redirect to challenge entry point
        return [
            'status' => 302,
            'headers' => [
                'Location' => "/enter/{$slug}",
                'Content-Type' => 'text/html; charset=utf-8',
            ],
            'body' => '',
        ];
    }

    /**
     * Serve challenge files (index.php, check.php, etc.)
     */
    public function serveChallenge(array $req): array
    {
        $slug = $req['params']['slug'] ?? '';
        $file = $req['params']['file'] ?? 'index.php';

        if ($slug === '') {
            return [
                'status' => 302,
                'headers' => ['Location' => '/', 'Content-Type' => 'text/html; charset=utf-8'],
                'body' => '',
            ];
        }

        // Security: prevent path traversal
        $slug = preg_replace('/[^a-zA-Z0-9_-]/', '', $slug);
        $file = basename($file); // Only allow filename, no path

        // Challenge directory
        $challengeRoot = '/srv/ctf/challenges';
        $challengePath = "{$challengeRoot}/{$slug}";

        // Check if challenge exists
        if (!is_dir($challengePath)) {
            return Router::render('error', [
                'title' => '找不到題目',
                'message' => "題目 {$slug} 不存在",
            ], 404);
        }

        // Build the file path
        $filePath = "{$challengePath}/{$file}";

        // Security: ensure file is within challenge directory (no traversal)
        $realPath = realpath($challengePath);
        $realFilePath = realpath($filePath);
        if ($realFilePath === false || strpos($realFilePath, $realPath) !== 0) {
            return Router::render('error', [
                'title' => '拒絕存取',
                'message' => '無效的檔案路徑',
            ], 403);
        }

        // Check if file exists
        if (!is_file($realFilePath)) {
            return Router::render('error', [
                'title' => '找不到檔案',
                'message' => "檔案 {$file} 不存在",
            ], 404);
        }

        // Set up session for the challenge
        @session_start();

        // Include and execute the challenge file
        // Capture output from the included file
        ob_start();
        try {
            include $realFilePath;
            $output = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            return Router::render('error', [
                'title' => '執行錯誤',
                'message' => '題目執行時發生錯誤',
            ], 500);
        }

        // Return the challenge output
        return [
            'status' => 200,
            'headers' => [
                'Content-Type' => 'text/html; charset=utf-8',
            ],
            'body' => $output,
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
