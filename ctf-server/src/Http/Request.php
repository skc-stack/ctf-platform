<?php
declare(strict_types=1);

namespace CTF\Server\Http;

/**
 * Lightweight HTTP request abstraction.
 */
final class Request
{
    public string $method;
    public string $path;
    /** @var array<string,string> */
    public array $query;
    /** @var array<string,mixed> */
    public array $post;
    /** @var array<string,string> */
    public array $headers;
    /** @var array<string,string> */
    public array $server;
    public string $body;
    /** @var array<string,mixed> */
    public array $routeParams = [];
    /** @var array<string,mixed>|null Device info set by DeviceAuth middleware */
    public ?array $device = null;

    public function __construct()
    {
        $this->method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        // Strip the front-controller base path so internal routes are clean.
        // e.g. SCRIPT_NAME=/ctf-server/public/index.php → base=/ctf-server/public
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
        $basePath = str_ends_with($scriptName, '.php') ? dirname($scriptName) : $scriptName;
        $basePath = str_replace('\\', '/', $basePath);
        $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
        $requestPath = parse_url($requestUri, PHP_URL_PATH) ?? '/';
        if ($basePath !== '' && $basePath !== '/' && $basePath !== '.') {
            if ($requestPath === $basePath) {
                $requestPath = '/';
            } elseif (str_starts_with($requestPath, $basePath . '/')) {
                $requestPath = substr($requestPath, strlen($basePath));
            }
        }
        $this->path = $requestPath === '' ? '/' : $requestPath;

        $this->query = $_GET;
        $this->post = $_POST;
        $this->server = $_SERVER;
        $this->body = (string)file_get_contents('php://input');

        $headers = [];
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', '-', substr($k, 5)))));
                $headers[$name] = (string)$v;
            }
        }
        // Also pick up Content-Type / Content-Length which PHP stores without HTTP_ prefix.
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['Content-Type'] = (string)$_SERVER['CONTENT_TYPE'];
        }
        if (isset($_SERVER['CONTENT_LENGTH'])) {
            $headers['Content-Length'] = (string)$_SERVER['CONTENT_LENGTH'];
        }
        $this->headers = $headers;
    }

    public function header(string $name): ?string
    {
        // Case-insensitive lookup. Apache may capitalize differently for custom headers.
        foreach ($this->headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }
        return null;
    }

    public function isJson(): bool
    {
        $ct = $this->header('Content-Type') ?? '';
        return str_contains($ct, 'application/json');
    }

    /** @return array<string,mixed> */
    public function json(): array
    {
        if ($this->body === '') {
            return [];
        }
        $data = json_decode($this->body, true);
        return is_array($data) ? $data : [];
    }

    public function ip(): string
    {
        return $this->server['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    public function userAgent(): string
    {
        $ua = $this->header('User-Agent') ?? '';
        return substr($ua, 0, 500);
    }
}
