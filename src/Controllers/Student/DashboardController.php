<?php
declare(strict_types=1);

namespace CTF\Server\Controllers\Student;

use CTF\Server\Controllers\BaseController;
use CTF\Server\Http\Request;
use CTF\Server\Http\Response;
use CTF\Server\Repositories\ChallengeRepository;
use CTF\Server\Repositories\SolveRepository;
use CTF\Server\Repositories\TaskSessionRepository;
use CTF\Server\Repositories\UserRepository;

final class DashboardController extends BaseController
{
    public function __construct(
        private readonly UserRepository $users = new UserRepository(),
        private readonly ChallengeRepository $challenges = new ChallengeRepository(),
        private readonly SolveRepository $solves = new SolveRepository(),
        private readonly TaskSessionRepository $tasks = new TaskSessionRepository(),
    ) {}

    public function index(Request $req): Response
    {
        $user = $_SESSION['user'];
        $studentId = (int)$user['id'];
        $stats = $this->users->statsForStudent($studentId) ?? [];
        $myScore = (int)($stats['total_score'] ?? 0);

        return $this->view($req, 'student/dashboard', [
            'title' => 'Student Dashboard — CTF LAB',
            'stats' => [
                'total_score' => $myScore,
                'rank' => $this->computeRank($myScore),
                'solved' => $this->solves->countForStudent($studentId),
                'active_tasks' => $this->solves->activeTaskCountForStudent($studentId),
            ],
            'last_solve' => $stats['last_solve'] ?? null,
            'challenges' => $this->challenges->listForStudent($studentId),
            'active_tasks_list' => $this->tasks->listActiveByStudent($studentId),
        ]);
    }

    private function computeRank(int $myScore): int
    {
        $row = \CTF\Server\Database\Connection::fetchOne(
            "SELECT COUNT(DISTINCT s.student_id) + 1 AS r
             FROM solves s
             JOIN users u ON u.id = s.student_id
             WHERE u.role = 'student' AND u.status = 'active'
               AND COALESCE((SELECT SUM(points) FROM solves WHERE student_id = s.student_id), 0) > :s",
            [':s' => $myScore]
        );
        return (int)($row['r'] ?? 1);
    }
}
