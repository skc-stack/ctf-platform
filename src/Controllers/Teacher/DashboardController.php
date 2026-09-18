<?php
declare(strict_types=1);

namespace CTF\Server\Controllers\Teacher;

use CTF\Server\Controllers\BaseController;
use CTF\Server\Database\Connection;
use CTF\Server\Http\Request;
use CTF\Server\Http\Response;

final class DashboardController extends BaseController
{
    public function index(Request $req): Response
    {
        $user = $_SESSION['user'];

        // Phase 1: count my challenges by status. Phase 2 will list them.
        $stats = [
            'draft' => 0,
            'published' => 0,
            'disabled' => 0,
        ];
        $rows = Connection::fetchAll(
            'SELECT status, COUNT(*) AS c FROM challenges WHERE teacher_id = :t GROUP BY status',
            [':t' => $user['id']]
        );
        foreach ($rows as $r) {
            $stats[$r['status']] = (int)$r['c'];
        }

        return $this->view($req, 'teacher/dashboard', [
            'title' => 'Teacher Dashboard — CTF LAB',
            'stats' => $stats,
        ]);
    }
}
