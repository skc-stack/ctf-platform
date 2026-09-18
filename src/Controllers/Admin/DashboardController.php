<?php
declare(strict_types=1);

namespace CTF\Server\Controllers\Admin;

use CTF\Server\Controllers\BaseController;
use CTF\Server\Http\Request;
use CTF\Server\Http\Response;
use CTF\Server\Repositories\UserRepository;
use CTF\Server\Services\AuditLog;

final class DashboardController extends BaseController
{
    public function __construct(
        private readonly UserRepository $users = new UserRepository(),
    ) {}

    public function index(Request $req): Response
    {
        $stats = [
            'students' => $this->users->countActiveByRole(UserRepository::ROLE_STUDENT),
            'teachers' => $this->users->countActiveByRole(UserRepository::ROLE_TEACHER),
            'pending_teachers' => count($this->users->listByStatus(UserRepository::STATUS_PENDING)),
        ];
        $recentAudit = AuditLog::recent(15);

        return $this->view($req, 'admin/dashboard', [
            'title' => 'Admin Dashboard — CTF LAB',
            'stats' => $stats,
            'recent_audit' => $recentAudit,
        ]);
    }
}
