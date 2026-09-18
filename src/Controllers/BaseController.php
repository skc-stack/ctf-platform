<?php
declare(strict_types=1);

namespace CTF\Server\Controllers;

use CTF\Server\Http\Request;
use CTF\Server\Http\Response;

abstract class BaseController
{
    protected function view(Request $req, string $template, array $vars = [], int $status = 200, ?string $layout = 'base'): Response
    {
        return Response::view($template, array_merge($vars, [
            'currentUser' => $_SESSION['user'] ?? null,
            'routePath' => $req->path,
        ]), $status, $layout);
    }

    protected function jsonOk(array $data = [], int $status = 200): Response
    {
        return Response::json(['success' => true, 'data' => $data], $status);
    }

    protected function jsonError(string $message, int $status = 400, array $extra = []): Response
    {
        return Response::json(array_merge(['success' => false, 'error' => $message], $extra), $status);
    }

    protected function flashError(string $msg): void
    {
        $_SESSION['_flash_error'] = $msg;
    }

    protected function flashSuccess(string $msg): void
    {
        $_SESSION['_flash_success'] = $msg;
    }
}
