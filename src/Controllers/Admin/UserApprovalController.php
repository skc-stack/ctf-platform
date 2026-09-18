<?php
declare(strict_types=1);

namespace CTF\Server\Controllers\Admin;

use CTF\Server\Controllers\BaseController;
use CTF\Server\Http\Request;
use CTF\Server\Http\Response;
use CTF\Server\Repositories\UserRepository;
use CTF\Server\Services\AuthService;

final class UserApprovalController extends BaseController
{
    public function __construct(
        private readonly AuthService $auth = new AuthService(),
        private readonly UserRepository $users = new UserRepository(),
    ) {}

    public function index(Request $req): Response
    {
        $pending = $this->users->listByStatus(UserRepository::STATUS_PENDING);
        $disabled = $this->users->listByStatus(UserRepository::STATUS_DISABLED);

        return $this->view($req, 'admin/users', [
            'title' => '使用者審核 — CTF LAB',
            'pending' => $pending,
            'disabled' => $disabled,
        ]);
    }

    public function approve(Request $req, string $id): Response
    {
        $this->approveOrDisable((int)$id, UserRepository::STATUS_ACTIVE, '已核准該使用者', $req);
        return Response::redirect('/admin/users');
    }

    public function disable(Request $req, string $id): Response
    {
        $this->approveOrDisable((int)$id, UserRepository::STATUS_DISABLED, '已停用該使用者', $req);
        return Response::redirect('/admin/users');
    }

    private function approveOrDisable(int $id, string $newStatus, string $flash, Request $req): void
    {
        $user = $this->users->findById($id);
        if (!$user) {
            $_SESSION['_flash_error'] = '找不到使用者';
            return;
        }
        $ok = $this->auth->approveUser($id, $newStatus, $req);
        $_SESSION[$ok ? '_flash_success' : '_flash_error'] = $ok ? $flash : '操作失敗';
    }
}
