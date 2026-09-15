<?php
/**
 * @var string $title
 * @var array $status ['ok' => bool, 'status' => int, 'body' => array, 'error' => ?string]
 * @var ?string $sync_result
 * @var ?string $reset_id
 * @var ?string $reset_ok
 */
ob_start();
$body = $status['body'] ?? [];
$hasCredential = $body['credential_present'] ?? false;
?>
<h1>歡迎使用 CTF Target Portal</h1>
<p class="lead">這台 VM 是你的 Target。所有安裝 / 啟用 / 同步都透過本地 Agent（127.0.0.1:8787）執行。</p>

<?php if ($sync_result === '1'): ?>
    <div class="alert ok">同步完成 ✓</div>
<?php elseif ($sync_result === '0'): ?>
    <div class="alert">同步失敗，請看下方 Agent 連線狀態。</div>
<?php endif; ?>
<?php if ($reset_id !== null): ?>
    <div class="alert <?= $reset_ok === '1' ? 'ok' : '' ?>">
        <?= $reset_ok === '1' ? '✓' : '✗' ?> 重置 <code><?= htmlspecialchars($reset_id, ENT_QUOTES, 'UTF-8') ?></code> <?= $reset_ok === '1' ? '成功' : '失敗' ?>
    </div>
<?php endif; ?>

<div class="stat-row">
    <div class="stat">
        <div class="k">Agent 版本</div>
        <div class="v"><?= htmlspecialchars((string)($body['agent_version'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></div>
    </div>
    <div class="stat">
        <div class="k">Target 版本</div>
        <div class="v"><?= htmlspecialchars((string)($body['target_version'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></div>
    </div>
    <div class="stat">
        <div class="k">Server</div>
        <div class="v" style="color: <?= $hasCredential ? 'var(--drafting-green)' : 'var(--drafting-amber)' ?>">
            <?= $hasCredential ? '已啟用 ✓' : '尚未啟用' ?>
        </div>
    </div>
    <div class="stat">
        <div class="k">Server URL</div>
        <div class="v" style="font-size: 14px"><?= htmlspecialchars((string)($body['server_url'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></div>
    </div>
</div>

<?php if (!$hasCredential): ?>
    <div class="card">
        <h2>第一步：啟用此裝置</h2>
        <p>到 CTF Server 學生儀表板取得一組啟用碼（<code>ACT-XXXX-XXXX-XXXX</code>，10 分鐘有效），貼回來。</p>
        <a href="/activate" class="btn primary">前往啟用 →</a>
    </div>
<?php else: ?>
    <div class="card">
        <h2>已啟用</h2>
        <p>裝置已綁定，隨時可以開始挑戰。</p>
        <div class="actions">
            <a href="/task" class="btn primary">貼 Task Token 開始挑戰 →</a>
            <form action="/sync" method="post" style="display:inline">
                <button type="submit" class="btn ghost">立即同步題目</button>
            </form>
        </div>
        <?php if (!empty($body['server_reachable'])): ?>
            <p class="hint">Agent ↔ Server 連線正常。</p>
        <?php elseif (isset($body['server_reachable'])): ?>
            <p class="hint" style="color: var(--drafting-red)">⚠ Server 連線失敗：<?= htmlspecialchars((string)($body['server_error'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>
    </div>
<?php endif; ?>

<p class="hint">所有操作都走本地 loopback。Portal 不直接連外網。</p>
<?php
$bodyHtml = ob_get_clean();
require __DIR__ . '/layout.php';
