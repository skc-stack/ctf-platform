<?php
use CTF\Server\Security\CSRF;
/** @var array $task */
/** @var ?string $task_token */
$task = $task ?? [];
$taskId = (int)($task['id'] ?? 0);
$expiresAt = $task['expires_at'] ?? '';
$startedAt = $task['started_at'] ?? '';
$status = $task['status'] ?? '';
$taskStatus = $task['task_status'] ?? 'token_not_copied';
$boundToDevice = !empty($task['device_id']);
$expired = $expiresAt !== '' && strtotime($expiresAt) < time();
$challengeTitle = $task['challenge_title'] ?? '未知題目';
$challengeSlug = $task['challenge_slug'] ?? '';
$challengeVersion = (int)($task['challenge_version'] ?? 0);
$entrypoint = '/challenge/' . ltrim($challengeSlug, '/') . '/';

// Status mapping
$statusLabels = [
    'token_not_copied' => '還沒複製Task Token',
    'token_copied' => '已複製Task Token',
    'token_validated' => '已驗證Task Token',
    'challenge_started' => '開始解題',
    'completed' => '完成解題',
];

$currentStatusLabel = $statusLabels[$taskStatus] ?? '未知狀態';
$isCompleted = $taskStatus === 'completed';
?>
<section class="ctf-dash">
    <h1 class="ctf-dash-title">
        <i class="bi bi-terminal"></i> Task Token
        <?php if ($challengeTitle !== '未知題目'): ?>
        — <?= htmlspecialchars($challengeTitle, ENT_QUOTES, 'UTF-8') ?>
        <?php endif; ?>
    </h1>
    <div class="ctf-dash-rule"></div>

    <!-- 狀態追蹤面板 -->
    <div class="task-status-panel" id="statusPanel" style="margin-bottom:1.5rem;padding:16px;border:1px solid var(--grid);background:#161b22">
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:12px">
            <i class="bi bi-list-task" style="color:var(--brass)"></i>
            <span style="font-weight:600;color:var(--paper)">解題進度</span>
            <span class="ctf-badge" id="statusBadge" style="margin-left:auto;font-size:12px;padding:4px 10px;background:var(--brass);color:var(--ink);border-radius:4px">
                <?= htmlspecialchars($currentStatusLabel, ENT_QUOTES, 'UTF-8') ?>
            </span>
        </div>
        <div class="task-progress-steps" style="display:flex;flex-direction:column;gap:8px">
            <div class="step <?= $taskStatus !== 'token_not_copied' ? 'done' : 'active' ?>" id="step1">
                <span class="step-icon"><i class="bi bi-1-circle-fill"></i></span>
                <span class="step-text">複製 Task Token</span>
            </div>
            <div class="step <?= in_array($taskStatus, ['token_copied','token_validated','challenge_started','completed']) ? 'done' : '' ?>" id="step2">
                <span class="step-icon"><i class="bi bi-2-circle-fill"></i></span>
                <span class="step-text">在 Target Portal 貼上 Token</span>
            </div>
            <div class="step <?= in_array($taskStatus, ['token_validated','challenge_started','completed']) ? 'done' : '' ?>" id="step3">
                <span class="step-icon"><i class="bi bi-3-circle-fill"></i></span>
                <span class="step-text">驗證成功，系統建立動態 Flag</span>
            </div>
            <div class="step <?= in_array($taskStatus, ['challenge_started','completed']) ? 'done' : '' ?>" id="step4">
                <span class="step-icon"><i class="bi bi-4-circle-fill"></i></span>
                <span class="step-text">在靶機上解題</span>
            </div>
            <div class="step <?= $taskStatus === 'completed' ? 'done' : '' ?>" id="step5">
                <span class="step-icon"><i class="bi bi-5-circle-fill"></i></span>
                <span class="step-text">提交 Flag 完成挑戰</span>
            </div>
        </div>
    </div>

    <style>
    .task-progress-steps .step { display:flex;align-items:center;gap:10px;padding:8px 12px;border-radius:6px;background:rgba(42,49,56,0.3);color:#8b969e;transition:all .3s }
    .task-progress-steps .step.done { background:rgba(106,190,106,0.15);color:#6abe6a }
    .task-progress-steps .step.active { background:rgba(191,111,58,0.15);color:#bf6f3a }
    .task-progress-steps .step-icon { font-size:18px }
    .task-progress-steps .step-text { font-size:14px }
    </style>

    <?php if ($task_token): ?>
    <div class="ctf-stat-card" style="margin-bottom:1rem;background:var(--ink);border:1px solid var(--grid);padding:16px">
        <div class="ctf-stat-label" style="display:flex;align-items:center;gap:8px">
            <i class="bi bi-key"></i> 你的 Task Token
            <button type="button" class="ctf-btn ctf-btn-sm ctf-btn-ghost" id="copyBtn"
                onclick="copyToken()">複製</button>
        </div>
        <div class="ctf-mono" id="tokenDisplay" style="font-size:18px;letter-spacing:2px;margin-top:8px;color:var(--drafting-cyan);word-break:break-all">
            <?= htmlspecialchars($task_token, ENT_QUOTES, 'UTF-8') ?>
        </div>
        <div id="copyFeedback" style="margin-top:8px;font-size:13px;color:#6abe6a;display:none">
            <i class="bi bi-check-circle-fill"></i> 已複製！請貼到 Target Portal
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
            <div class="ctf-mono" style="font-size:13px;padding-top:14px">
                <code style="word-break:break-all"><?= htmlspecialchars($entrypoint, ENT_QUOTES, 'UTF-8') ?></code>
            </div>
            <?php if ($challengeSlug && $status === 'active' && !$expired && !$isCompleted): ?>
            <div style="margin-top:12px">
                <button type="button" class="ctf-btn ctf-btn-primary" id="openChallengeBtn"
                    onclick="openChallenge()">
                    <i class="bi bi-box-arrow-up-right"></i> 開啟題目
                </button>
            </div>
            <?php endif; ?>
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
            <div class="ctf-stat-label"><i class="bi bi-shield-check"></i> 系統狀態</div>
            <div class="ctf-stat-value <?= $status === 'active' && !$expired ? 'ctf-stat-value-green' : 'ctf-stat-value-amber' ?>" style="font-size:16px;padding-top:18px">
                <?= $expired ? '已過期' : htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>
            </div>
            <div class="ctf-stat-meta">
                <?php if ($boundToDevice): ?>
                    <i class="bi bi-link-45deg"></i> 已綁定裝置
                <?php else: ?>
                    <i class="bi bi-unlock"></i> 尚未綁定
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if ($status === 'active' && !$expired && !$isCompleted): ?>
    <h2 class="ctf-dash-sub" id="flagSection">/ 繳交 Flag</h2>
    <form id="flagForm" class="ctf-form">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars(CSRF::token(), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="task_id" value="<?= $taskId ?>">
        <label class="ctf-field">
            <span class="ctf-field-label">Flag（格式：<code>flag{...}</code>）</span>
            <input type="text" name="flag" id="flagInput" required placeholder="flag{32-char-hex}"
                   pattern="flag\{[a-fA-F0-9]+\}"
                   style="font-family:var(--font-mono);font-size:18px;letter-spacing:1px;">
        </label>
        <div id="flagFeedback" style="margin-top:8px;display:none"></div>
        <div class="ctf-form-actions">
            <button type="submit" class="ctf-btn ctf-btn-primary" id="submitBtn">
                <i class="bi bi-flag-fill"></i> 送出 Flag
            </button>
        </div>
    </form>
    <?php elseif ($isCompleted): ?>
    <div class="ctf-flash ctf-flash-success" style="margin-top:1rem">
        <i class="bi bi-trophy-fill"></i> 恭喜！你已成功完成此挑戰！
    </div>
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
</section>

<script>
const taskId = <?= $taskId ?>;
const taskToken = <?= json_encode($task_token ?? '') ?>;
const entrypoint = <?= json_encode($entrypoint) ?>;

// Copy token and update status
async function copyToken() {
    if (!taskToken) return;
    try {
        await navigator.clipboard.writeText(taskToken);
        document.getElementById('copyFeedback').style.display = 'block';
        // Update status to copied
        await fetch(`/api/v1/student/task/${taskId}/status`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ task_status: 'token_copied' })
        });
        updateProgressUI('token_copied');
    } catch (e) {
        alert('複製失敗：' + e);
    }
}

// Update progress UI
function updateProgressUI(status) {
    const steps = {
        'token_not_copied': 0,
        'token_copied': 1,
        'token_validated': 2,
        'challenge_started': 3,
        'completed': 4
    };
    const currentStep = steps[status] ?? 0;
    const stepElements = ['step1','step2','step3','step4','step5'];
    stepElements.forEach((id, idx) => {
        const el = document.getElementById(id);
        if (idx < currentStep) {
            el.className = 'step done';
        } else if (idx === currentStep) {
            el.className = 'step active';
        } else {
            el.className = 'step';
        }
    });
    const badge = document.getElementById('statusBadge');
    const labels = {
        'token_not_copied': '還沒複製Task Token',
        'token_copied': '已複製Task Token',
        'token_validated': '已驗證Task Token',
        'challenge_started': '開始解題',
        'completed': '完成解題'
    };
    badge.textContent = labels[status] || status;
}

// Open challenge and update status
async function openChallenge() {
    // Update status to challenge_started before opening
    try {
        await fetch(`/api/v1/student/task/${taskId}/status`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ task_status: 'challenge_started' })
        });
        updateProgressUI('challenge_started');
    } catch (e) {
        console.error('Failed to update status:', e);
    }
    // Open challenge in new tab
    window.open(entrypoint, '_blank');
}

// Poll for status updates
async function pollStatus() {
    try {
        const resp = await fetch(`/api/v1/student/task/${taskId}/status`);
        const data = await resp.json();
        if (data.success) {
            updateProgressUI(data.data.task_status);
            if (data.data.task_status === 'completed') {
                document.getElementById('flagSection')?.remove();
                document.getElementById('flagForm')?.remove();
            }
        }
    } catch (e) {
        console.error('Poll error:', e);
    }
}

// Submit flag
document.getElementById('flagForm')?.addEventListener('submit', async function(e) {
    e.preventDefault();
    const btn = document.getElementById('submitBtn');
    const input = document.getElementById('flagInput');
    const feedback = document.getElementById('flagFeedback');
    const flag = input.value.trim();

    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-hourglass"></i> 驗證中...';

    try {
        const csrfInput = this.querySelector('[name=_csrf]');
        const resp = await fetch('/api/v1/student/submit', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({
                _csrf: csrfInput.value,
                task_id: taskId,
                flag: flag
            })
        });
        const result = await resp.json();

        if (result.success) {
            feedback.className = 'ctf-flash ctf-flash-success';
            feedback.innerHTML = '<i class="bi bi-check-circle-fill"></i> 答對了！+' + result.data.points + ' 分';
            feedback.style.display = 'block';
            updateProgressUI('completed');
            document.getElementById('flagSection')?.remove();
        } else {
            feedback.className = 'ctf-flash ctf-flash-error';
            feedback.innerHTML = '<i class="bi bi-x-circle-fill"></i> ' + (result.error || 'Flag 不正確');
            feedback.style.display = 'block';
        }
    } catch (e) {
        feedback.className = 'ctf-flash ctf-flash-error';
        feedback.innerHTML = '<i class="bi bi-x-circle-fill"></i> 提交失敗：' + e;
        feedback.style.display = 'block';
    }

    btn.disabled = false;
    btn.innerHTML = '<i class="bi bi-flag-fill"></i> 送出 Flag';
});

// Start polling every 3 seconds
setInterval(pollStatus, 3000);
</script>
