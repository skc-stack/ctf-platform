<?php
use CTF\Server\Security\CSRF;

/** @var array $challenge */
$challenge = $challenge ?? [];
$id = (int)($challenge['id'] ?? 0);
?>
<section class="ctf-dash">
    <h1 class="ctf-dash-title">
        <i class="bi bi-collection"></i> <?= htmlspecialchars($challenge['title'] ?? '', ENT_QUOTES, 'UTF-8') ?>
    </h1>
    <div class="ctf-dash-rule"></div>

    <div class="ctf-dash-meta">
        <i class="bi bi-tag"></i> <code><?= htmlspecialchars($challenge['slug'] ?? '', ENT_QUOTES, 'UTF-8') ?></code>
        ｜ <i class="bi bi-bookmark"></i> <?= htmlspecialchars($challenge['category'] ?? '', ENT_QUOTES, 'UTF-8') ?>
        ｜ <i class="bi bi-bar-chart"></i> <?= htmlspecialchars($challenge['difficulty'] ?? '', ENT_QUOTES, 'UTF-8') ?>
        ｜ <i class="bi bi-coin"></i> <?= (int)($challenge['points'] ?? 0) ?> 分
        ｜ <i class="bi bi-people"></i> <?= (int)($challenge['solve_count'] ?? 0) ?> 人解過
        <?php if ((int)($challenge['solved_by_me'] ?? 0) === 1): ?>
        ｜ <span class="ctf-tag ctf-tag-success"><i class="bi bi-check-circle-fill"></i> 已解</span>
        <?php endif; ?>
    </div>

    <!-- 計分標準說明 -->
    <div style="margin:1rem 0;padding:12px 16px;background:rgba(25,135,84,0.1);border:1px solid rgba(25,135,84,0.3);border-radius:8px;font-size:14px">
        <div style="font-weight:600;margin-bottom:8px;color:#4ade80">
            <i class="bi bi-info-circle-fill"></i> 計分標準
        </div>
        <div style="color:#86efac;font-weight:500">
            第 1 次解成功：<?= (int)($challenge['points'] ?? 0) ?> 分（滿分）<br>
            第 2 次解成功：<?= (int)round(($challenge['points'] ?? 100) * 0.9) ?> 分（90%）<br>
            第 3 次解成功：<?= (int)round(($challenge['points'] ?? 100) * 0.81) ?> 分（81%）<br>
            第 4 次以上以此類推…
        </div>
        <?php if ((int)($challenge['my_attempts'] ?? 0) > 0): ?>
        <div style="margin-top:10px;padding-top:8px;border-top:1px solid rgba(25,135,84,0.2);color:#4ade80;font-weight:500">
            <i class="bi bi-keyboard"></i> 你已嘗試 <?= (int)($challenge['my_attempts'] ?? 0) ?> 次
            <?php if ((int)($challenge['solved_by_me'] ?? 0) === 1): ?>
                ，解題成功花了 <strong style="color:#86efac"><?= (int)($challenge['my_solve_attempts'] ?? 1) ?></strong> 次
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <?php if (!empty($challenge['description'])): ?>
        <div class="challenge-description" style="margin:1.5rem 0">
            <?= nl2br(preg_replace('/<script\b[^>]*>.*?<\/script>/si', '', (string)($challenge['description']))) ?>
        </div>
    <?php else: ?>
        <p class="ctf-muted" style="margin:1rem 0">此題目尚無描述。</p>
    <?php endif; ?>

    <div class="ctf-dash-actions">
        <?php if ((int)($challenge['solved_by_me'] ?? 0) === 1): ?>
            <span class="ctf-tag ctf-tag-success" style="font-size:16px;padding:8px 16px">
                <i class="bi bi-check-circle-fill"></i> 已解過此題
            </span>
        <?php elseif (!empty($active_task)): ?>
            <?php
            $taskStatusLabels = [
                'token_not_copied' => '還沒複製Task Token',
                'token_copied' => '已複製Task Token',
                'token_validated' => '已驗證Task Token',
                'challenge_started' => '解題進行中',
                'completed' => '已完成',
            ];
            $taskStatus = $active_task['task_status'] ?? 'token_not_copied';
            $statusLabel = $taskStatusLabels[$taskStatus] ?? $taskStatus;
            ?>
            <div style="display:inline-flex;align-items:center;gap:12px">
                <span class="ctf-tag" style="background:rgba(191,111,58,0.2);color:#bf6f3a;border:1px solid #bf6f3a;padding:6px 12px">
                    <i class="bi bi-hourglass-split"></i> 你有進行中的任務
                </span>
                <a href="/student/task/<?= (int)$active_task['id'] ?>" class="ctf-btn ctf-btn-primary">
                    <i class="bi bi-arrow-right"></i> 繼續任務
                </a>
            </div>
        <?php else: ?>
            <form action="/api/v1/student/task/start" method="post" style="display:inline">
                <?= CSRF::field() ?>
                <input type="hidden" name="challenge_id" value="<?= $id ?>">
                <button type="submit" class="ctf-btn ctf-btn-primary">
                    <i class="bi bi-play-fill"></i> 啟動 Task
                </button>
            </form>
        <?php endif; ?>
        <a href="/student/challenges" class="ctf-btn ctf-btn-ghost">
            <i class="bi bi-arrow-left"></i> 回到題目列表
        </a>
    </div>

    <?php if (!empty($active_task)): ?>
    <div class="ctf-dash-hint" style="margin-top:1.5rem">
        <i class="bi bi-info-circle"></i> 任務過期時間：<?= htmlspecialchars($active_task['expires_at'], ENT_QUOTES, 'UTF-8') ?>
    </div>
    <?php else: ?>
    <div class="ctf-dash-hint" style="margin-top:1.5rem">
        <i class="bi bi-info-circle"></i> 啟動 Task 後，請到 Target Portal 貼上 Token 來開啟挑戰。
    </div>
    <?php endif; ?>
</section>
