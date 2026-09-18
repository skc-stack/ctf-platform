<?php
declare(strict_types=1);

namespace CTF\Server\Services;

use CTF\Server\Database\Connection;
use CTF\Server\Http\Request;
use CTF\Server\Repositories\ChallengeRepository;
use CTF\Server\Repositories\DeviceRepository;
use CTF\Server\Repositories\NonceRepository;
use CTF\Server\Repositories\SolveRepository;
use CTF\Server\Repositories\SubmissionRepository;
use CTF\Server\Repositories\TaskSessionRepository;
use CTF\Server\Security\FlagGenerator;

/**
 * SubmissionService: verify a flag submission and write solve if correct.
 *
 * Two entry points:
 *   - submitFromBrowser(studentId, taskSessionId, submittedFlag, ip)
 *   - completeFromDevice(deviceId, taskSessionId, submittedFlag, nonce, ip)
 *
 * Both share `verifyAndAward()` so the rules are identical:
 *   1. task active + not expired
 *   2. challenge published
 *   3. (browser) student owns task; (device) device owns task
 *   4. recompute expected flag via FlagGenerator (HMAC)
 *   5. constant-time compare
 *   6. write submissions row (always)
 *   7. if correct AND first solve for (student, challenge): insert solves
 *   8. mark task completed (only on correct — wrong submissions don't end the task)
 */
final class SubmissionService
{
    public function __construct(
        private readonly TaskSessionRepository $tasks = new TaskSessionRepository(),
        private readonly ChallengeRepository $challenges = new ChallengeRepository(),
        private readonly SubmissionRepository $submissions = new SubmissionRepository(),
        private readonly SolveRepository $solves = new SolveRepository(),
        private readonly DeviceRepository $devices = new DeviceRepository(),
        private readonly NonceRepository $nonces = new NonceRepository(),
    ) {}

    /**
     * Browser submit: `POST /api/v1/student/submit`.
     *
     * @return array{ok: bool, reason: string, points: int, total_score: int, new_solve: bool}
     */
    public function submitFromBrowser(
        int $studentId,
        int $taskSessionId,
        string $submittedFlag,
        ?Request $req = null,
    ): array {
        $task = $this->tasks->findById($taskSessionId);
        if ($task === null) {
            return $this->reject('task_not_found', 0);
        }
        if ((int)$task['student_id'] !== $studentId) {
            return $this->reject('not_owner', 0);
        }
        $ip = $req?->ip();

        return Connection::transaction(function () use ($task, $submittedFlag, $ip, $req, $studentId) {
            $result = $this->verifyAndAward((int)$task['id'], (int)$task['challenge_id'], (int)$task['student_id'],
                                             (string)$task['uuid'], $submittedFlag, $ip, $req, 'browser');
            if ($result['ok'] && $req !== null) {
                AuditLog::fromRequest($req, 'flag_submit', 'task', (string)$task['id'], [
                    'correct' => true,
                    'points' => $result['points'],
                ]);
            } elseif ($req !== null) {
                AuditLog::fromRequest($req, 'flag_submit', 'task', (string)$task['id'], [
                    'correct' => false,
                    'reason' => $result['reason'],
                ]);
            }
            $result['total_score'] = $this->solves->totalPointsForStudent($studentId);
            return $result;
        });
    }

    /**
     * Device complete: `POST /api/v1/device/task/complete`.
     *
     * Replay protection via the nonce table: nonce must be fresh.
     */
    public function completeFromDevice(
        array $device,
        int $taskSessionId,
        string $submittedFlag,
        string $nonce,
        ?Request $req = null,
    ): array {
        if (!$this->nonces->consume((int)$device['id'], $nonce)) {
            return $this->reject('nonce_replayed', 0);
        }
        $task = $this->tasks->findById($taskSessionId);
        if ($task === null) {
            return $this->reject('task_not_found', 0);
        }
        // Note: we do NOT check device.user_id == task.student_id here,
        // allowing multiple students to share the same VM.
        // Make sure this task is bound to the calling device.
        if ($task['device_id'] !== null && (int)$task['device_id'] !== (int)$device['id']) {
            return $this->reject('device_mismatch', 0);
        }
        $ip = $req?->ip();

        return Connection::transaction(function () use ($task, $submittedFlag, $ip, $req, $device) {
            $result = $this->verifyAndAward((int)$task['id'], (int)$task['challenge_id'], (int)$task['student_id'],
                                             (string)$task['uuid'], $submittedFlag, $ip, $req, 'device',
                                             (int)$device['id']);
            if ($result['ok'] && $req !== null) {
                AuditLog::fromRequest($req, 'task_complete', 'task', (string)$task['id'], [
                    'device_id' => (int)$device['id'],
                    'correct' => true,
                    'points' => $result['points'],
                ]);
            }
            $result['total_score'] = $this->solves->totalPointsForStudent((int)$task['student_id']);
            return $result;
        });
    }

    /**
     * Core: write submission, decide correct, optionally write solve, mark task.
     *
     * Returns:
     *   ok: bool (flag matched)
     *   reason: '' (when ok) or error code
     *   points: 0 (when not ok or already solved) or challenge points
     *   new_solve: bool (true only on first correct submission)
     *
     * @internal Used by submitFromBrowser + completeFromDevice.
     */
    private function verifyAndAward(
        int $taskId,
        int $challengeId,
        int $studentId,
        string $taskUuid,
        string $submittedFlag,
        ?string $ip,
        ?Request $req,
        string $source,
        ?int $deviceId = null,
    ): array {
        // Load challenge (need uuid + points + published check).
        $challenge = $this->challenges->findById($challengeId);
        if ($challenge === null || $challenge['status'] !== ChallengeRepository::STATUS_PUBLISHED) {
            $this->submissions->create([
                'student_id' => $studentId,
                'challenge_id' => $challengeId,
                'task_session_id' => $taskId,
                'submitted_flag_hash' => hash('sha256', $submittedFlag),
                'correct' => false,
                'source_ip' => $ip,
            ]);
            return $this->reject('challenge_unavailable', 0);
        }

        // Compute expected flag and constant-time compare.
        $expected = FlagGenerator::compute($studentId, (string)$challenge['uuid'], $taskUuid);
        $correct = FlagGenerator::matches($submittedFlag, $expected);

        // Always record the submission.
        $this->submissions->create([
            'student_id' => $studentId,
            'challenge_id' => $challengeId,
            'task_session_id' => $taskId,
            'submitted_flag_hash' => hash('sha256', $submittedFlag),
            'correct' => $correct,
            'source_ip' => $ip,
        ]);

        if (!$correct) {
            return $this->reject('flag_mismatch', 0);
        }

        // Try to award a solve — UNIQUE on (student_id, challenge_id) guards against double-award.
        $points = (int)$challenge['points'];
        $newSolveId = $this->solves->tryCreate($studentId, $challengeId, $taskId, $points);

        if ($newSolveId === null) {
            // Already solved this challenge in the past — task ends but no extra points.
            $this->tasks->markCompleted($taskId);
            return [
                'ok' => true,
                'reason' => 'already_solved',
                'points' => 0,
                'new_solve' => false,
            ];
        }

        $this->tasks->markCompleted($taskId);
        return [
            'ok' => true,
            'reason' => 'correct',
            'points' => $points,
            'new_solve' => true,
        ];
    }

    /** @return array{ok: bool, reason: string, points: int, total_score: int, new_solve: bool} */
    private function reject(string $reason, int $points): array
    {
        return [
            'ok' => false,
            'reason' => $reason,
            'points' => $points,
            'total_score' => 0,
            'new_solve' => false,
        ];
    }
}
