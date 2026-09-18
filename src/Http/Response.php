<?php
declare(strict_types=1);

namespace CTF\Server\Http;

/**
 * Response builder.
 */
final class Response
{
    public int $status = 200;
    /** @var array<string,string> */
    public array $headers = [];
    public string $body = '';

    public static function make(string $body = '', int $status = 200, array $headers = []): self
    {
        $r = new self();
        $r->body = $body;
        $r->status = $status;
        $r->headers = $headers;
        return $r;
    }

    public static function json(array $data, int $status = 200): self
    {
        return self::make(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $status, [
            'Content-Type' => 'application/json; charset=utf-8',
        ]);
    }

    public static function redirect(string $to, int $status = 302): self
    {
        return self::make('', $status, ['Location' => $to]);
    }

    public static function view(string $template, array $vars = [], int $status = 200, ?string $layout = 'base'): self
    {
        $content = View::render($template, $vars, $layout);
        return self::make($content, $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function withStatus(int $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function withBody(string $body): self
    {
        $this->body = $body;
        return $this;
    }

    public function send(): void
    {
        if (headers_sent()) {
            return;
        }
        http_response_code($this->status);
        foreach ($this->headers as $k => $v) {
            header("{$k}: {$v}");
        }
        echo $this->body;
    }
}
