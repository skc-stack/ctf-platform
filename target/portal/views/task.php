<?php
/**
 * @var string $title
 * @var string $prefill
 * @var ?array $result
 * @var ?string $error
 */
ob_start();
?>
<h1>開始挑戰</h1>
<p class="lead">從 CTF Server 學生儀表板取得 Task Token（<code>TASK-XXXX-XXXX-XXXX-XXXX</code>），貼到下面。Agent 會送到 Server 驗證並綁定此裝置。</p>

<?php if ($error !== null): ?>
    <div class="alert">✗ <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<div class="card">
    <form action="/task" method="post" class="cform">
        <label>
            <span class="lbl">Task Token</span>
            <input type="text" name="task_token" required maxlength="32"
                   pattern="TASK-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}"
                   placeholder="TASK-XXXX-XXXX-XXXX-XXXX"
                   style="text-transform:uppercase;letter-spacing:3px;font-size:18px"
                   value="<?= htmlspecialchars($prefill, ENT_QUOTES, 'UTF-8') ?>" autofocus>
        </label>
        <div class="actions">
            <button type="submit" class="btn primary">驗證並開啟挑戰</button>
            <a href="/" class="btn ghost">取消</a>
        </div>
    </form>
</div>

<?php if ($result !== null): ?>
    <div class="card">
        <h2>✓ 已綁定</h2>
        <p>Task 已驗證並綁定到此 VM。可以打開題目：</p>
        <div class="stat-row">
            <div class="stat">
                <div class="k">題目 ID</div>
                <div class="v">#<?= htmlspecialchars((string)($result['challenge_id'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></div>
            </div>
            <div class="stat">
                <div class="k">題目版本</div>
                <div class="v">v<?= htmlspecialchars((string)($result['challenge_version'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></div>
            </div>
            <div class="stat">
                <div class="k">過期時間</div>
                <div class="v" style="font-size:14px"><?= htmlspecialchars((string)($result['expires_at'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></div>
            </div>
        </div>
        <?php if (!empty($result['entrypoint'])): ?>
            <h2>Entrypoint</h2>
            <p style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                <code style="font-size:18px;padding:8px 12px"><?= htmlspecialchars($result['entrypoint'], ENT_QUOTES, 'UTF-8') ?></code>
                <button type="button" class="copy-btn"
                        onclick="copyText('<?= htmlspecialchars($result['entrypoint'], ENT_QUOTES, 'UTF-8') ?>', this)">複製</button>
                <a class="btn primary" href="<?= htmlspecialchars($result['entrypoint'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">在新分頁開啟 →</a>
            </p>
        <?php endif; ?>
        <p class="hint">解題後到 CTF Server 學生儀表板交 flag 得分。</p>
    </div>
<?php endif; ?>
<?php
$bodyHtml = ob_get_clean();
require __DIR__ . '/layout.php';
