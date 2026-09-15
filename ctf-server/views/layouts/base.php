<?php
use CTF\Server\Support\Config;
use CTF\Server\Security\CSRF;

/** @var string $title */
/** @var string $content */
/** @var array|null $currentUser */
/** @var string $routePath */

$user = $currentUser;
$isLoggedIn = $user !== null;
$flashSuccess = $_SESSION['_flash_success'] ?? null;
$flashError = $_SESSION['_flash_error'] ?? null;
unset($_SESSION['_flash_success'], $_SESSION['_flash_error']);

$navLinks = match ($user['role'] ?? null) {
    'admin' => [
        ['label' => '儀表板', 'href' => '/admin', 'icon' => 'bi-speedometer2'],
        ['label' => '使用者審核', 'href' => '/admin/users', 'icon' => 'bi-people'],
        ['label' => '稽核日誌', 'href' => '#', 'icon' => 'bi-journal-text'],
    ],
    'teacher' => [
        ['label' => '儀表板', 'href' => '/teacher', 'icon' => 'bi-speedometer2'],
        ['label' => '題目', 'href' => '/teacher/challenges', 'icon' => 'bi-collection'],
        ['label' => '群組', 'href' => '/teacher/groups', 'icon' => 'bi-people-fill'],
        ['label' => '排行榜', 'href' => '/leaderboard', 'icon' => 'bi-trophy'],
    ],
    'student' => [
        ['label' => '群組', 'href' => '/student/groups', 'icon' => 'bi-people-fill'],
        ['label' => '題目', 'href' => '#', 'icon' => 'bi-grid-3x3-gap'],
        ['label' => '排行榜', 'href' => '/leaderboard', 'icon' => 'bi-trophy'],
    ],
    default => [
        ['label' => '題目', 'href' => '#', 'icon' => 'bi-grid-3x3-gap'],
        ['label' => '排行榜', 'href' => '/leaderboard', 'icon' => 'bi-trophy'],
    ],
};

// Server / session metadata for the layout footer.
$appVersion = (string)\CTF\Server\Support\Config::get('APP_VERSION', '0.4');
$appEnv     = (string)\CTF\Server\Support\Config::get('APP_ENV', 'local');
$now        = date('Y-m-d H:i');
$renderMs   = defined('CTF_BOOT_TS') ? round((microtime(true) - CTF_BOOT_TS) * 1000, 1) : null;

$userLabel   = $user['display_name'] ?? $user['username'] ?? null;
$roleLabel   = match ($user['role'] ?? null) {
    'admin'   => '管理員',
    'teacher' => '老師',
    'student' => '學生',
    default   => null,
};
$loginAt     = isset($user['login_at']) ? date('Y-m-d H:i', (int)$user['login_at']) : null;
$loginIp     = $user['login_ip'] ?? null;
$phpVersion  = PHP_VERSION;
$serverSoft  = php_sapi_name();
?>
<!doctype html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars(($title ?? 'CTF LAB') . ' · ' . Config::get('APP_NAME', 'CTF LAB'), ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="/assets/css/site.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500;600;700&family=IBM+Plex+Serif:ital,wght@0,400;0,500;0,600;0,700;1,400&family=JetBrains+Mono:wght@400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
</head>
<body>
    <header class="ctf-nav">
        <div class="ctf-nav-inner">
            <a class="ctf-brand" href="<?= $isLoggedIn ? dashboardUrlForRole($user['role']) : '/' ?>">
                <span class="ctf-brand-mark"></span>
                <span class="ctf-brand-text">CTF LAB</span>
            </a>
            <nav class="ctf-nav-links">
                <?php foreach ($navLinks as $link): ?>
                    <a href="<?= htmlspecialchars($link['href'], ENT_QUOTES, 'UTF-8') ?>"
                       class="<?= $routePath === $link['href'] ? 'is-active' : '' ?>">
                        <?= htmlspecialchars($link['label'], ENT_QUOTES, 'UTF-8') ?>
                    </a>
                <?php endforeach; ?>
                <?php if ($isLoggedIn): ?>
                    <span class="ctf-nav-user">
                        <i class="bi bi-person-fill"></i>
                        <span class="ctf-nav-user-name"><?= htmlspecialchars($user['display_name'], ENT_QUOTES, 'UTF-8') ?></span>
                        <span class="ctf-nav-user-role"><?= htmlspecialchars($roleLabel, ENT_QUOTES, 'UTF-8') ?></span>
                    </span>
                    <form action="/logout" method="post" class="ctf-nav-logout">
                        <?= CSRF::field() ?>
                        <button type="submit" class="ctf-btn ctf-btn-ghost ctf-btn-sm">登出</button>
                    </form>
                <?php else: ?>
                    <a href="/register" class="ctf-nav-link">註冊</a>
                    <a href="/login">登入</a>
                <?php endif; ?>
            </nav>
        </div>
    </header>

    <?php if ($flashSuccess): ?>
        <div class="ctf-flash ctf-flash-success"><i class="bi bi-check-circle-fill"></i> <?= htmlspecialchars($flashSuccess, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <?php if ($flashError): ?>
        <div class="ctf-flash ctf-flash-error"><i class="bi bi-exclamation-triangle-fill"></i> <?= htmlspecialchars($flashError, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <main class="ctf-main">
        <div class="ctf-meta-top">
            <div>SHEET — <?= htmlspecialchars($title ?? 'CTF LAB', ENT_QUOTES, 'UTF-8') ?></div>
            <div class="ctf-meta-top-right">
                v<?= htmlspecialchars($appVersion, ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars($now, ENT_QUOTES, 'UTF-8') ?>
                <?php if ($renderMs !== null): ?>
                <br>render <?= htmlspecialchars((string)$renderMs, ENT_QUOTES, 'UTF-8') ?>ms
                <?php endif; ?>
            </div>
        </div>

        <?= $content ?>

        <div class="ctf-title-block">
            <div>
                <strong><?= htmlspecialchars($appName = \CTF\Server\Support\Config::get('APP_NAME', 'CTF LAB'), ENT_QUOTES, 'UTF-8') ?></strong><br>
                v<?= htmlspecialchars($appVersion, ENT_QUOTES, 'UTF-8') ?> · env=<?= htmlspecialchars($appEnv, ENT_QUOTES, 'UTF-8') ?>
            </div>
            <div>
                <?php if ($isLoggedIn): ?>
                    <strong><?= htmlspecialchars($userLabel, ENT_QUOTES, 'UTF-8') ?></strong> · <?= htmlspecialchars($roleLabel, ENT_QUOTES, 'UTF-8') ?><br>
                    登入 @ <?= htmlspecialchars($loginAt, ENT_QUOTES, 'UTF-8') ?> · IP <?= htmlspecialchars((string)$loginIp, ENT_QUOTES, 'UTF-8') ?>
                <?php else: ?>
                    <strong>訪客</strong><br>
                    IP <?= htmlspecialchars((string)$loginIp, ENT_QUOTES, 'UTF-8') ?>
                <?php endif; ?>
            </div>
            <div style="text-align: right;">
                PHP <?= htmlspecialchars($phpVersion, ENT_QUOTES, 'UTF-8') ?><br>
                <strong><?= htmlspecialchars((string)$serverSoft, ENT_QUOTES, 'UTF-8') ?></strong>
            </div>
        </div>
    </main>
</body>
</html>
<?php
function dashboardUrlForRole(string $role): string {
    return match ($role) {
        'admin' => '/admin',
        'teacher' => '/teacher',
        default => '/student',
    };
}
