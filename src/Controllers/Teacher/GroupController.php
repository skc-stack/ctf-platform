<?php
declare(strict_types=1);

namespace CTF\Server\Controllers\Teacher;

use CTF\Server\Controllers\BaseController;
use CTF\Server\Http\Request;
use CTF\Server\Http\Response;
use CTF\Server\Repositories\GroupMemberRepository;
use CTF\Server\Repositories\GroupRepository;
use CTF\Server\Services\GroupService;

/**
 * Teacher-facing group CRUD.
 *
 * Routes (see routes/web.php):
 *   GET    /teacher/groups
 *   GET    /teacher/groups/new
 *   POST   /teacher/groups
 *   GET    /teacher/groups/{id}
 *   POST   /teacher/groups/{id}/regenerate-code
 *   POST   /teacher/groups/{id}/remove/{userId}
 *   POST   /teacher/groups/{id}/delete
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
        $groups = $this->groups->listByTeacher($userId);
        return $this->view($req, 'teacher/groups/index', [
            'title' => '我的群組 — CTF LAB',
            'groups' => $groups,
        ]);
    }

    public function new(Request $req): Response
    {
        return $this->view($req, 'teacher/groups/new', [
            'title' => '建立群組 — CTF LAB',
            'old' => $_SESSION['_old'] ?? [],
        ]);
    }

    public function create(Request $req): Response
    {
        $userId = (int)$_SESSION['user']['id'];
        $name = (string)($req->post['name'] ?? '');
        $description = (string)($req->post['description'] ?? '');
        $maxRaw = trim((string)($req->post['max_members'] ?? ''));

        $maxMembers = null;
        if ($maxRaw !== '') {
            if (!ctype_digit($maxRaw) || (int)$maxRaw < 1) {
                $this->flashError('人數上限需為正整數（不限制請留空）');
                $_SESSION['_old'] = ['name' => $name, 'description' => $description, 'max_members' => $maxRaw];
                return Response::redirect('/teacher/groups/new');
            }
            $maxMembers = (int)$maxRaw;
        }

        try {
            $group = $this->service->createGroup($userId, $name, $description, $maxMembers, $req);
            $this->flashSuccess("群組「{$group['name']}」已建立，邀請碼：{$group['join_code']}");
            return Response::redirect('/teacher/groups/' . (int)$group['id']);
        } catch (\InvalidArgumentException $e) {
            $this->flashError($e->getMessage());
            $_SESSION['_old'] = ['name' => $name, 'description' => $description, 'max_members' => $maxRaw];
            return Response::redirect('/teacher/groups/new');
        }
    }

    public function show(Request $req, string $id): Response
    {
        $userId = (int)$_SESSION['user']['id'];
        $group = $this->groups->findById((int)$id);
        if (!$group) {
            $this->flashError('找不到此群組');
            return Response::redirect('/teacher/groups');
        }
        if ((int)$group['teacher_id'] !== $userId) {
            $this->flashError('你並非此群組的管理者');
            return Response::redirect('/teacher/groups');
        }
        $members = $this->members->listActiveMembers((int)$group['id']);

        return $this->view($req, 'teacher/groups/show', [
            'title' => '群組 — ' . $group['name'],
            'group' => $group,
            'members' => $members,
        ]);
    }

    public function regenerateCode(Request $req, string $id): Response
    {
        $userId = (int)$_SESSION['user']['id'];
        try {
            $newCode = $this->service->regenerateCode($userId, (int)$id, $req);
            $this->flashSuccess("已換新邀請碼：{$newCode}");
        } catch (\InvalidArgumentException $e) {
            $this->flashError($e->getMessage());
        }
        return Response::redirect('/teacher/groups/' . (int)$id);
    }

    public function removeMember(Request $req, string $id, string $userId): Response
    {
        $teacherId = (int)$_SESSION['user']['id'];
        try {
            $this->service->remove($teacherId, (int)$id, (int)$userId, $req);
            $this->flashSuccess('已將該學生從群組中移除');
        } catch (\InvalidArgumentException $e) {
            $this->flashError($e->getMessage());
        }
        return Response::redirect('/teacher/groups/' . (int)$id);
    }

    public function delete(Request $req, string $id): Response
    {
        $teacherId = (int)$_SESSION['user']['id'];
        try {
            $ok = $this->service->deleteGroup($teacherId, (int)$id, $req);
            $this->flashSuccess($ok ? '群組已刪除' : '找不到此群組');
        } catch (\InvalidArgumentException $e) {
            $this->flashError($e->getMessage());
        }
        return Response::redirect('/teacher/groups');
    }
}
