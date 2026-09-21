<?php
declare(strict_types=1);

/**
 * Target Portal — minimal HTTP router.
 *
 * Why not a framework:
 *   - Portal binds to 127.0.0.1 only — zero external attack surface.
 *   - Three endpoints (/, /activate, /task, /reset) — framework overhead
 *     would dwarf the actual code.
 *   - No DB access; we proxy everything to the local Python Agent on
 *     127.0.0.1:8787. So state management lives there.
 */

namespace CTF\Portal;

final class Router
{
    /** @var array<int,array{0:string,1:string,2:array<int,callable(array<string,mixed>):array<string,mixed>>,3:array{0:string,1:string}}> */
    private array $routes = [];

    /**
     * @param array<int,callable(array<string,mixed>):array<string,mixed>> $middleware
     * @param array{0:string,1:string} $handler [ControllerClass::class, actionMethod]
     */
    public function add(string $method, string $path, array $middleware, array $handler): void
    {
        $this->routes[] = [strtoupper($method), $path, $middleware, $handler];
    }

    public function get(string $path, array $middleware, array $handler): void
    {
        $this->add('GET', $path, $middleware, $handler);
    }

    public function post(string $path, array $middleware, array $handler): void
    {
        $this->add('POST', $path, $middleware, $handler);
    }

    /**
     * @param array<string,mixed> $req (method, path, query, post, ip)
     * @return array{status:int, headers:array<string,string>, body:string}
     */
    public function dispatch(array $req): array
    {
        $path = $req['path'] ?? '/';
        $method = strtoupper($req['method'] ?? 'GET');

        foreach ($this->routes as [$rmethod, $rpath, $middleware, $handler]) {
            if ($rmethod !== $method) {
                continue;
            }
            // Convert /task/{id} to a regex with a capture group.
            // First pass: extract parameter names
            $paramNames = [];
            $rpath2 = $rpath;
            if (preg_match_all('#\{([a-zA-Z_]+)\}#', $rpath, $matches)) {
                $paramNames = $matches[1];
            }
            $regex = '#^' . preg_replace('#\{([a-zA-Z_]+)\}#', '([^/]+)', $rpath) . '/?$#';
            if (!preg_match($regex, $path, $m)) {
                continue;
            }
            array_shift($m);
            // Map captured values to parameter names
            $params = [];
            foreach ($paramNames as $i => $name) {
                $params[$name] = $m[$i] ?? null;
            }
            $req['params'] = $params;

            // Run middleware in reverse.
            $pipeline = array_reverse($middleware);
            $core = function (array $r) use ($handler) {
                [$class, $action] = $handler;
                if (!class_exists($class)) {
                    return ['status' => 500, 'body' => "controller class missing: $class"];
                }
                $controller = new $class();
                if (!method_exists($controller, $action)) {
                    return ['status' => 500, 'body' => "action missing: $class::$action"];
                }
                return $controller->$action($r);
            };
            $next = $core;
            foreach ($pipeline as $mw) {
                $next = function (array $r) use ($mw, $next) {
                    return $mw($r, $next);
                };
            }
            return $next($req);
        }

        return [
            'status' => 404,
            'headers' => ['Content-Type' => 'text/html; charset=utf-8'],
            'body' => $this->renderError(404, 'Not Found', "沒有此路徑：{$path}"),
        ];
    }

    /**
     * Static render — controllers call this without needing a Router instance.
     * @param array<string,mixed> $vars
     */
    public static function render(string $template, array $vars = [], int $status = 200): array
    {
        $body = View::render($template, $vars);
        return [
            'status' => $status,
            'headers' => ['Content-Type' => 'text/html; charset=utf-8'],
            'body' => $body,
        ];
    }

    public function renderError(int $status, string $title, string $message): string
    {
        $tpl = <<<'HTML'
<!doctype html><meta charset=utf-8>
<title>%s — CTF TARGET</title>
<style>
body{font-family:'JetBrains Mono',ui-monospace,Menlo,Consolas,monospace;background:#0e1116;color:#c8d3df;margin:0;padding:48px;min-height:100vh;display:flex;align-items:center;justify-content:center}
.box{max-width:560px;padding:32px;border:1px solid #2a3138;background:#161b22}
h1{color:#bf6f3a;margin:0 0 16px;font-size:48px;letter-spacing:2px}
p{color:#8b969e;margin:0;line-height:1.6}
code{background:#0e1116;color:#f7c884;padding:2px 6px;border:1px solid #2a3138}
</style>
<div class=box>
<h1>%s</h1>
<p>%s</p>
</div>
HTML;
        return sprintf($tpl, htmlspecialchars((string)$status), htmlspecialchars($title), $message);
    }
}
