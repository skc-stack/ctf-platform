<?php
declare(strict_types=1);

namespace CTF\Server\Controllers;

use CTF\Server\Http\Request;
use CTF\Server\Http\Response;
use CTF\Server\Services\CaptchaService;
use CTF\Server\Support\Config;

final class CaptchaController extends BaseController
{
    public function __construct(
        private readonly CaptchaService $captcha = new CaptchaService(),
    ) {}

    /**
     * GET /captcha — returns a PNG captcha image.
     * Generates a fresh answer every request (one-shot use).
     *
     * In non-production env (APP_DEBUG=true), the answer is echoed back in
     * the X-Captcha-Debug response header. This is for E2E tests + manual
     * inspection only and MUST be disabled in production.
     */
    public function image(Request $req): Response
    {
        $result = $this->captcha->generate();
        $png    = $this->captcha->image((string)$result['answer']);

        $r = (new Response())
            ->withStatus(200)
            ->withHeader('Content-Type', 'image/png')
            ->withHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->withHeader('Pragma', 'no-cache')
            ->withBody($png);

        if (Config::bool('APP_DEBUG', false)) {
            $r->withHeader('X-Captcha-Debug', (string)$result['answer']);
        }

        return $r;
    }
}
