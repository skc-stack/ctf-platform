<?php
declare(strict_types=1);

use CTF\Server\Http\Router;
use CTF\Server\Controllers\HomeController;
use CTF\Server\Controllers\AuthController;
use CTF\Server\Controllers\CaptchaController;
use CTF\Server\Controllers\Student\DashboardController as StudentDashboard;
use CTF\Server\Controllers\Student\GroupController as StudentGroups;
use CTF\Server\Controllers\Student\DeviceController as StudentDevice;
use CTF\Server\Controllers\SubmissionController;
use CTF\Server\Controllers\TaskController;
use CTF\Server\Controllers\Teacher\DashboardController as TeacherDashboard;
use CTF\Server\Controllers\Teacher\GroupController as TeacherGroups;
use CTF\Server\Controllers\Teacher\ChallengeController as TeacherChallenges;
use CTF\Server\Controllers\Admin\DashboardController as AdminDashboard;
use CTF\Server\Controllers\Admin\UserApprovalController;
use CTF\Server\Controllers\Admin\DeviceController as AdminDeviceController;
use CTF\Server\Controllers\DeviceController;
use CTF\Server\Controllers\DeviceApiController;
use CTF\Server\Middleware\Guest;
use CTF\Server\Middleware\Auth;
use CTF\Server\Middleware\CSRF;
use CTF\Server\Middleware\RateLimitLogin;
use CTF\Server\Middleware\RateLimitPasswordReset;
use CTF\Server\Middleware\RequireAdmin;
use CTF\Server\Middleware\RequireTeacher;
use CTF\Server\Middleware\RequireStudent;
use CTF\Server\Middleware\DeviceAuth;
use CTF\Server\Middleware\RateLimitTaskValidate;
use CTF\Server\Middleware\RateLimitFlagSubmit;

/**
 * Web routes — landing, auth, dashboards, admin, leaderboard.
 */
function ctf_web_routes(Router $router): void
{
    // Public
    $router->get('/', [], [HomeController::class, 'index']);
    $router->get('/health', [], [HomeController::class, 'health']);
    $router->get('/captcha', [], [CaptchaController::class, 'image']);

    // Auth (guest-only)
    $router->get('/login', [Guest::class], [AuthController::class, 'showLogin']);
    $router->post('/login', [CSRF::class, RateLimitLogin::class, Guest::class], [AuthController::class, 'login']);
    $router->post('/logout', [Auth::class, CSRF::class], [AuthController::class, 'logout']);

    // Register (single entry — student/teacher chosen via ?role= in URL or _role in POST)
    $router->get('/register', [Guest::class], [AuthController::class, 'showRegister']);
    $router->post('/register', [CSRF::class, Guest::class], [AuthController::class, 'register']);

    // Email verification + password reset (public)
    $router->get('/verify-email', [], [AuthController::class, 'showVerifyEmail']);
    $router->get('/password/reset', [], [AuthController::class, 'showResetRequest']);
    $router->post('/password/reset', [CSRF::class, RateLimitPasswordReset::class], [AuthController::class, 'resetRequest']);
    $router->get('/password/reset/confirm', [], [AuthController::class, 'showResetConfirm']);
    $router->post('/password/reset/confirm', [CSRF::class], [AuthController::class, 'resetConfirm']);

    // Dashboards
    $router->get('/student', [Auth::class, RequireStudent::class], [StudentDashboard::class, 'index']);
    $router->get('/teacher', [Auth::class, RequireTeacher::class], [TeacherDashboard::class, 'index']);
    $router->get('/admin', [Auth::class, RequireAdmin::class], [AdminDashboard::class, 'index']);

    // Admin: user approval
    $router->get('/admin/users', [Auth::class, RequireAdmin::class], [UserApprovalController::class, 'index']);
    $router->post('/admin/users/{id}/approve', [Auth::class, RequireAdmin::class, CSRF::class], [UserApprovalController::class, 'approve']);
    $router->post('/admin/users/{id}/disable', [Auth::class, RequireAdmin::class, CSRF::class], [UserApprovalController::class, 'disable']);

    // Admin: devices
    $router->get('/admin/devices', [Auth::class, RequireAdmin::class], [AdminDeviceController::class, 'index']);
    $router->post('/admin/devices/{id}/revoke', [Auth::class, RequireAdmin::class, CSRF::class], [AdminDeviceController::class, 'revoke']);
    $router->post('/admin/devices/codes/{id}', [Auth::class, RequireAdmin::class, CSRF::class], [AdminDeviceController::class, 'deleteActivationCode']);

    // Teacher: groups
    $router->get('/teacher/groups', [Auth::class, RequireTeacher::class], [TeacherGroups::class, 'index']);
    $router->get('/teacher/groups/new', [Auth::class, RequireTeacher::class], [TeacherGroups::class, 'new']);
    $router->post('/teacher/groups', [Auth::class, RequireTeacher::class, CSRF::class], [TeacherGroups::class, 'create']);
    $router->get('/teacher/groups/{id}', [Auth::class, RequireTeacher::class], [TeacherGroups::class, 'show']);
    $router->post('/teacher/groups/{id}/regenerate-code', [Auth::class, RequireTeacher::class, CSRF::class], [TeacherGroups::class, 'regenerateCode']);
    $router->post('/teacher/groups/{id}/remove/{userId}', [Auth::class, RequireTeacher::class, CSRF::class], [TeacherGroups::class, 'removeMember']);
    $router->post('/teacher/groups/{id}/delete', [Auth::class, RequireTeacher::class, CSRF::class], [TeacherGroups::class, 'delete']);

    // Teacher: challenges
    $router->get('/teacher/challenges', [Auth::class, RequireTeacher::class], [TeacherChallenges::class, 'index']);
    $router->get('/teacher/challenges/new', [Auth::class, RequireTeacher::class], [TeacherChallenges::class, 'new']);
    $router->post('/teacher/challenges', [Auth::class, RequireTeacher::class, CSRF::class], [TeacherChallenges::class, 'create']);
    $router->get('/teacher/challenges/{id}', [Auth::class, RequireTeacher::class], [TeacherChallenges::class, 'show']);
    $router->get('/teacher/challenges/{id}/edit', [Auth::class, RequireTeacher::class], [TeacherChallenges::class, 'edit']);
    $router->post('/teacher/challenges/{id}', [Auth::class, RequireTeacher::class, CSRF::class], [TeacherChallenges::class, 'update']);
    $router->post('/teacher/challenges/{id}/upload', [Auth::class, RequireTeacher::class, CSRF::class], [TeacherChallenges::class, 'upload']);
    $router->post('/teacher/challenges/{id}/files', [Auth::class, RequireTeacher::class, CSRF::class], [TeacherChallenges::class, 'addFiles']);
    $router->post('/teacher/challenges/{id}/files/delete', [Auth::class, RequireTeacher::class, CSRF::class], [TeacherChallenges::class, 'deleteFile']);
    $router->post('/teacher/challenges/{id}/publish', [Auth::class, RequireTeacher::class, CSRF::class], [TeacherChallenges::class, 'publish']);
    $router->post('/teacher/challenges/{id}/disable', [Auth::class, RequireTeacher::class, CSRF::class], [TeacherChallenges::class, 'disable']);
    $router->post('/teacher/challenges/{id}/delete', [Auth::class, RequireTeacher::class, CSRF::class], [TeacherChallenges::class, 'delete']);
    $router->post('/teacher/challenges/{id}/groups', [Auth::class, RequireTeacher::class, CSRF::class], [TeacherChallenges::class, 'setGroups']);

    // Student: groups
    $router->get('/student/groups', [Auth::class, RequireStudent::class], [StudentGroups::class, 'index']);
    $router->get('/student/groups/join', [Auth::class, RequireStudent::class], [StudentGroups::class, 'showJoin']);
    $router->post('/student/groups/join', [Auth::class, RequireStudent::class, CSRF::class], [StudentGroups::class, 'join']);
    $router->post('/student/groups/{id}/leave', [Auth::class, RequireStudent::class, CSRF::class], [StudentGroups::class, 'leave']);

    // Student: devices
    $router->get('/student/devices', [Auth::class, RequireStudent::class], [StudentDevice::class, 'index']);
    $router->post('/api/v1/student/devices/request-code', [Auth::class, RequireStudent::class], [StudentDevice::class, 'requestCode']);
    $router->post('/api/v1/student/devices/revoke/{id}', [Auth::class, RequireStudent::class, CSRF::class], [StudentDevice::class, 'revoke']);

    // Student: tasks
    $router->get('/student/task/{id}', [Auth::class, RequireStudent::class], [TaskController::class, 'show']);
    $router->post('/student/task/{id}/cancel', [Auth::class, RequireStudent::class, CSRF::class], [TaskController::class, 'cancel']);
    $router->post('/api/v1/student/task/start', [Auth::class, RequireStudent::class, CSRF::class], [TaskController::class, 'start']);
    $router->post('/api/v1/student/submit', [Auth::class, RequireStudent::class, CSRF::class, RateLimitFlagSubmit::class], [SubmissionController::class, 'submitFromBrowser']);

    // Device: task validate + complete + submit-flag (no nonce)
    $router->post('/api/v1/device/task/validate', [DeviceAuth::class, RateLimitTaskValidate::class], [TaskController::class, 'validateApi']);
    $router->post('/api/v1/device/task/complete', [DeviceAuth::class, RateLimitFlagSubmit::class], [SubmissionController::class, 'completeFromDevice']);
    $router->post('/api/v1/device/submit-flag', [DeviceAuth::class, RateLimitFlagSubmit::class], [SubmissionController::class, 'submitFlagFromDevice']);

    // Leaderboard (public — but visible to anyone)
    $router->get('/leaderboard', [], [HomeController::class, 'leaderboard']);

    // API me (Phase 1)
    $router->get('/api/v1/me', [Auth::class], [AuthController::class, 'me']);

    // Device API (Phase 3)
    $router->post('/api/v1/device/activate', [], [DeviceApiController::class, 'activate']);
    $router->get('/api/v1/device/info', [DeviceAuth::class], [DeviceController::class, 'info']);
    $router->post('/api/v1/device/heartbeat', [DeviceAuth::class], [DeviceController::class, 'heartbeat']);
    // Teacher: CKEditor image upload
    $router->post('/api/v1/teacher/upload-image', [Auth::class, RequireTeacher::class], [TeacherChallenges::class, 'uploadImage']);
}
