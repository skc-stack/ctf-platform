<?php
declare(strict_types=1);

namespace CTF\Portal;

/**
 * HTTP client for the local Python Agent at 127.0.0.1:8787.
 *
 * The Portal never holds any secret directly — only the Agent on this VM
 * has the device_token. Every call here is a local HTTP request.
 *
 * Uses curl because PHP on the Portal side does NOT have the strict
 * disable_functions list of the Server (the Portal's Apache config sets
 * it, but curl is permitted). file_get_contents() would also work if
 * allow_url_fopen is on.
 */
final class AgentClient
{
    private string $baseUrl;
    private int $timeoutSec;

    public function __construct(string $baseUrl = 'http://127.0.0.1:8787', int $timeoutSec = 5)
    {
        $this->baseUrl = $baseUrl;
        $this->timeoutSec = $timeoutSec;
    }

    /**
     * @return array{ok: bool, status: int, body: array<string,mixed>, error: ?string}
     */
    public function get(string $path): array
    {
        return $this->request('GET', $path, null);
    }

    /**
     * @param array<string,mixed>|null $json
     */
    public function post(string $path, ?array $json = null): array
    {
        return $this->request('POST', $path, $json);
    }

    /**
     * @param array<string,mixed>|null $json
     * @return array{ok: bool, status: int, body: array<string,mixed>, error: ?string}
     */
    private function request(string $method, string $path, ?array $json): array
    {
        $url = rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/');
        $ch = curl_init($url);
        $headers = ['Accept: application/json'];
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeoutSec);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
        if ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            if ($json !== null) {
                $body = json_encode($json, JSON_UNESCAPED_UNICODE);
                if ($body === false) {
                    curl_close($ch);
                    return ['ok' => false, 'status' => 0, 'body' => [], 'error' => 'json_encode failed'];
                }
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
                $headers[] = 'Content-Type: application/json';
            }
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($resp === false) {
            return ['ok' => false, 'status' => $code, 'body' => [], 'error' => $err];
        }
        $decoded = json_decode((string)$resp, true);
        $body = is_array($decoded) ? $decoded : [];
        return [
            'ok' => $code >= 200 && $code < 300,
            'status' => $code,
            'body' => $body,
            'error' => $code >= 400 ? ($body['error'] ?? "HTTP $code") : null,
        ];
    }
}
