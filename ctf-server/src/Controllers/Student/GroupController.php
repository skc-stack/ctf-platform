<?php
declare(strict_types=1);

namespace CTF\Server\Controllers\Student;

use CTF\Server\Controllers\BaseController;
use CTF\Server\Http\Request;
use CTF\Server\Http\Response;
use CTF\Server\Repositories\GroupMemberRepository;
use CTF\Server\Repositories\GroupRepository;
use CTF\Server\Services\GroupService;

/**
 * Student-facing group actions.
 *
 * Routes (see routes/web.php):
 *   GET    /student/groups
 *   GET    /student/groups/join
 *   POST   /student/groups/join
 *   POST   /student/groups/{id}/leave
 */
final class GroupController extends BaseController
{
    public function __construct(
        private readonly GroupService $service = new GroupService(),
        private readonly GroupRepository $groups = new GroupRepository(),
        private readonly GroupMemberRepository $members = new GroupMemberRepository(),
    ) {}

    public function index(Request $req): Response
    {
        $userId = (int)$_SESSION['user']['id'];
        $groups = $this->members->listActiveByStudent($userId);
        return $this->view($req, 'student/groups/index', [
            'title' => '我的群組 — CTF LAB',
            'groups' => $groups,
        ]);
    }

    public function showJoin(Request $req): Response
    {
        return $this->view($req, 'student/groups/join', [
            'title' => '加入群組 — CTF LAB',
            'old' => $_SESSION['_old'] ?? [],
            'prefillCode' => isset($_GET['code']) ? strtoupper(trim((string)$_GET['code'])) : '',
        ]);
    }

    public function join(Request $req): Response
    {
        $userId = (int)$_SESSION['user']['id'];
        $code = (string)($req->post['join_code'] ?? '');
        try {
            $group = $this->service->joinByCode($userId, $code, $req);
            $this->flashSuccess("已加入「{$group['name']}」");
            return Response::redirect('/student/groups');
        } catch (\InvalidArgumentException $e) {
            $this->flashError($e->getMessage());
            $_SESSION['_old'] = ['join_code' => $code];
            return Response::redirect('/student/groups/join');
        }
    }

    public function leave(Request $req, string $id): Response
    {
        $userId = (int)$_SESSION['user']['id'];
        try {
            $this->service->leave((int)$id, $userId, $req);
            $this->flashSuccess('已離開群組');
        } catch (\InvalidArgumentException $e) {
            $this->flashError($e->getMessage());
        }
        return Response::redirect('/student/groups');
    }
}
