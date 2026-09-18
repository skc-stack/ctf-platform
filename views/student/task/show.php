<?php
use CTF\Server\Security\CSRF;
/** @var array $task */
/** @var ?string $task_token */
$task = $task ?? [];
$taskId = (int)($task['id'] ?? 0);
$expiresAt = $task['expires_at'] ?? '';
$startedAt = $task['started_at'] ?? '';
$status = $task['status'] ?? '';
$boundToDevice = !empty($task['device_id']);
$expired = $expiresAt !== '' && strtotime($expiresAt) < time();
$challengeTitle = $task['challenge_title'] ?? '未知題目';
$challengeSlug = $task['challenge_slug'] ?? '';
$challengeUuid = $task['challenge_uuid'] ?? '';
$challengeVersion = (int)($task['challenge_version'] ?? 0);
$entrypoint = '/challenge/' . ltrim($challengeSlug, '/') . '/';
?>
<section class="ctf-dash">
    <h1 class="ctf-dash-title">
        <i class="bi bi-terminal"></i> Task Token
        <?php if ($challengeTitle !== '未知題目'): ?>
        — <?= htmlspecialchars($challengeTitle, ENT_QUOTES, 'UTF-8') ?>
        <?php endif; ?>
    </h1>
    <div class="ctf-dash-rule"></div>

    <?php if ($task_token): ?>
    <div class="ctf-flash ctf-flash-success" style="margin-bottom:1rem">
        <i class="bi bi-check-circle-fill"></i> Task Token 已建立，請在 120 分鐘內貼到 Target Portal
    </div>
    <div class="ctf-stat-card" style="margin-bottom:1rem;background:var(--ink);border:1px solid var(--grid);padding:16px">
        <div class="ctf-stat-label" style="display:flex;align-items:center;gap:8px">
            <i class="bi bi-key"></i> 你的 Task Token（只會顯示一次）
            <button type="button" class="ctf-btn ctf-btn-sm ctf-btn-ghost" onclick="
                navigator.clipboard.writeText('<?= htmlspecialchars(addslashes($task_token), ENT_QUOTES, 'UTF-8') ?>').then(function(){
                    var btn=this;btn.textContent='已複製!';setTimeout(function(){btn.textContent='複製';},2000);
                }.bind(this)).catch(function(){});
            ">複製</button>
        </div>
        <div class="ctf-mono" style="font-size:20px;letter-spacing:3px;margin-top:8px;color:var(--drafting-cyan);word-break:break-all">
            <?= htmlspecialchars($task_token, ENT_QUOTES, 'UTF-8') ?>
        </div>
    </div>
    <?php else: ?>
    <div class="ctf-flash ctf-flash-warning" style="margin-bottom:1rem">
        <i class="bi bi-exclamation-triangle-fill"></i> Token 已過期或遺失。請到學生儀表板重新啟動任務。
    </div>
    <?php endif; ?>

    <p class="ctf-dash-lead">
        把這個 Token 貼到 Target Portal。Target VM 會用它向 Server 驗證身份並開啟挑戰。
    </p>

    <?php if ($challengeSlug): ?>
    <div class="ctf-stat-grid">
        <div class="ctf-stat-card">
            <div class="ctf-stat-label"><i class="bi bi-collection"></i> 題目名稱</div>
            <div class="ctf-stat-value" style="font-size:18px;padding-top:14px"><?= htmlspecialchars($challengeTitle, ENT_QUOTES, 'UTF-8') ?></div>
        </div>
        <div class="ctf-stat-card">
            <div class="ctf-stat-label"><i class="bi bi-coin"></i> 題目 Slug</div>
            <div class="ctf-mono" style="font-size:14px;padding-top:14px"><?= htmlspecialchars($challengeSlug, ENT_QUOTES, 'UTF-8') ?></div>
        </div>
        <div class="ctf-stat-card">
            <div class="ctf-stat-label"><i class="bi bi-shield-check"></i> 版本</div>
            <div class="ctf-stat-value ctf-stat-value-cyan">v<?= $challengeVersion ?></div>
        </div>
        <div class="ctf-stat-card">
            <div class="ctf-stat-label"><i class="bi bi-link-45deg"></i> 挑戰入口</div>
            <div class="ctf-mono" style="font-size:13px;padding-top:14px;color:var(--drafting-cyan)">
                <code style="word-break:break-all"><?= htmlspecialchars($entrypoint, ENT_QUOTES, 'UTF-8') ?></code>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="ctf-stat-grid">
        <div class="ctf-stat-card">
            <div class="ctf-stat-label"><i class="bi bi-hash"></i> Task ID</div>
            <div class="ctf-stat-value ctf-stat-value-cyan">#<?= $taskId ?></div>
        </div>
        <div class="ctf-stat-card">
            <div class="ctf-stat-label"><i class="bi bi-clock"></i> 開始時間</div>
            <div class="ctf-mono" style="font-size:16px;padding-top:14px"><?= htmlspecialchars($startedAt, ENT_QUOTES, 'UTF-8') ?></div>
        </div>
        <div class="ctf-stat-card">
            <div class="ctf-stat-label"><i class="bi bi-hourglass-split"></i> 過期時間</div>
            <div class="ctf-mono" style="font-size:16px;padding-top:14px"><?= htmlspecialchars($expiresAt, ENT_QUOTES, 'UTF-8') ?></div>
        </div>
        <div class="ctf-stat-card">
            <div class="ctf-stat-label"><i class="bi bi-shield-check"></i> 狀態</div>
            <div class="ctf-stat-value <?= $status === 'active' && !$expired ? 'ctf-stat-value-green' : 'ctf-stat-value-amber' ?>" style="font-size:20px;padding-top:18px">
                <?= $expired ? '已過期' : htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>
            </div>
            <div class="ctf-stat-meta">
                <?php if ($boundToDevice): ?>
                    <i class="bi bi-link-45deg"></i> 已綁定裝置 #<?= (int)$task['device_id'] ?>
                <?php else: ?>
                    <i class="bi bi-unlock"></i> 尚未綁定
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="ctf-dash-meta">
        <i class="bi bi-info-circle"></i> 啟用流程：到 Target Portal（通常是 <code>http://127.0.0.1:8080</code>）貼上 Token，Agent 會把 Token 送到 Server 驗證。
    </div>

    <?php if ($status === 'active' && !$expired): ?>
    <h2 class="ctf-dash-sub">/ 繳交 Flag</h2>
    <form action="/api/v1/student/submit" method="post" class="ctf-form">
        <?= CSRF::field() ?>
        <input type="hidden" name="task_id" value="<?= $taskId ?>">
        <label class="ctf-field">
            <span class="ctf-field-label">Flag（格式：<code>flag{...}</code>）</span>
            <input type="text" name="flag" required placeholder="flag{32-char-hex}"
                   pattern="flag\{[a-fA-F0-9]+\}"
                   style="font-family:var(--font-mono);font-size:18px;letter-spacing:1px;">
        </label>
        <div class="ctf-form-actions">
            <button type="submit" class="ctf-btn ctf-btn-primary">
                <i class="bi bi-flag-fill"></i> 送出 Flag
            </button>
        </div>
    </form>
    <?php endif; ?>

    <div class="ctf-dash-actions">
        <?php if ($status === 'active' && !$expired): ?>
            <form action="/student/task/<?= $taskId ?>/cancel" method="post" style="display:inline"
                  onsubmit="return confirm('確定要取消這個 Task？取消後 Token 立即失效。');">
                <?= CSRF::field() ?>
                <button type="submit" class="ctf-btn ctf-btn-danger">
                    <i class="bi bi-x-circle"></i> 取消任務
                </button>
            </form>
        <?php endif; ?>
        <a href="/student" class="ctf-btn ctf-btn-ghost">
            <i class="bi bi-arrow-left"></i> 回學生儀表板
        </a>
    </div>

    <p class="ctf-dash-hint">
        <i class="bi bi-shield-lock"></i> Token 只在啟動時顯示一次（已重導到這個頁面後就無法再看到明文）。
        若遺失請重新啟動任務。
    </p>
</section>
