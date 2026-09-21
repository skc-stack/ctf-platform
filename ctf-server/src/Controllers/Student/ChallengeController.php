<?php
declare(strict_types=1);

namespace CTF\Server\Controllers\Student;

use CTF\Server\Controllers\BaseController;
use CTF\Server\Http\Request;
use CTF\Server\Http\Response;
use CTF\Server\Repositories\ChallengeRepository;
use CTF\Server\Repositories\TaskSessionRepository;

/**
 * Student challenge browsing.
 */
final class ChallengeController extends BaseController
{
    public function __construct(
        private readonly ChallengeRepository $challenges = new ChallengeRepository(),
        private readonly TaskSessionRepository $tasks = new TaskSessionRepository(),
    ) {}

    /**
     * List all visible challenges for this student with pagination and filters.
     */
    public function index(Request $req): Response
    {
        $studentId = (int)$_SESSION['user']['id'];
        $page = max(1, (int)($req->get['page'] ?? 1));
        $perPage = 10;
        $category = $req->get['category'] ?? null;
        $difficulty = $req->get['difficulty'] ?? null;

        $result = $this->challenges->listForStudentPaginated(
            $studentId,
            $page,
            $perPage,
            $category !== '' ? $category : null,
            $difficulty !== '' ? $difficulty : null
        );

        return $this->view($req, 'student/challenges/index', [
            'title' => '題目列表 — CTF LAB',
            'challenges' => $result['rows'],
            'pagination' => [
                'page' => $result['page'],
                'total' => $result['total'],
                'perPage' => $result['perPage'],
                'pages' => $result['pages'],
            ],
            'filters' => [
                'category' => $category,
                'difficulty' => $difficulty,
            ],
            'categories' => $this->challenges->listCategories(),
            'difficulties' => $this->challenges->listDifficulties(),
        ]);
    }

    /**
     * Show challenge detail for students.
     */
    public function show(Request $req, string $id): Response
    {
        $studentId = (int)$_SESSION['user']['id'];
        $challenge = $this->challenges->findById((int)$id);

        if (!$challenge) {
            $this->flashError('找不到此題目');
            return Response::redirect('/student/challenges');
        }

        // Check visibility: student must be in an allowed group (if any)
        $boundGroupIds = $this->challenges->listGroupIdsForChallenge((int)$id);
        if (!empty($boundGroupIds)) {
            $placeholders = [];
            $params = [':sid' => $studentId, ':active' => 'active'];
            foreach ($boundGroupIds as $i => $gid) {
                $key = ":g{$i}";
                $placeholders[] = $key;
                $params[$key] = (int)$gid;
            }
            $row = \CTF\Server\Database\Connection::fetchOne(
                'SELECT COUNT(*) AS c FROM group_members
                 WHERE student_id = :sid AND status = :active AND group_id IN (' . implode(',', $placeholders) . ')',
                $params
            );
            if ((int)($row['c'] ?? 0) === 0) {
                $this->flashError('你不在此題目的允許群組中');
                return Response::redirect('/student/challenges');
            }
        }

        // Get solve count and whether this student solved it
        $row = \CTF\Server\Database\Connection::fetchOne(
            'SELECT COUNT(*) AS c FROM solves WHERE challenge_id = :cid',
            [':cid' => (int)$id]
        );
        $challenge['solve_count'] = (int)($row['c'] ?? 0);

        $solvedRow = \CTF\Server\Database\Connection::fetchOne(
            'SELECT 1 FROM solves WHERE challenge_id = :cid AND student_id = :sid LIMIT 1',
            [':cid' => (int)$id, ':sid' => $studentId]
        );
        $challenge['solved_by_me'] = $solvedRow !== null;

        // Check if student has an active task for this challenge
        $activeTask = \CTF\Server\Database\Connection::fetchOne(
            'SELECT id, task_status, expires_at, started_at FROM task_sessions
             WHERE student_id = :sid AND challenge_id = :cid AND status = :status AND expires_at > NOW()
             ORDER BY started_at DESC LIMIT 1',
            [':sid' => $studentId, ':cid' => (int)$id, ':status' => 'active']
        );

        return $this->view($req, 'student/challenges/show', [
            'title' => htmlspecialchars($challenge['title'] ?? '題目', ENT_QUOTES, 'UTF-8') . ' — CTF LAB',
            'challenge' => $challenge,
            'active_task' => $activeTask ?: null,
        ]);
    }
}
