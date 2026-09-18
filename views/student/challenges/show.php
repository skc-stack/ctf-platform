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

    <?php if (!empty($challenge['description'])): ?>
        <div class="challenge-description" style="margin:1.5rem 0">
            <?= nl2br(preg_replace('/<script\b[^>]*>.*?<\/script>/si', '', (string)($challenge['description']))) ?>
        </div>
    <?php else: ?>
        <p class="ctf-muted" style="margin:1rem 0">此題目尚無描述。</p>
    <?php endif; ?>

    <div class="ctf-dash-actions">
        <?php if ((int)($challenge['solved_by_me'] ?? 0) === 0): ?>
            <form action="/api/v1/student/task/start" method="post" style="display:inline">
                <?= CSRF::field() ?>
                <input type="hidden" name="challenge_id" value="<?= $id ?>">
                <button type="submit" class="ctf-btn ctf-btn-primary">
                    <i class="bi bi-play-fill"></i> 啟動 Task
                </button>
            </form>
        <?php else: ?>
            <span class="ctf-tag ctf-tag-success" style="font-size:16px;padding:8px 16px">
                <i class="bi bi-check-circle-fill"></i> 已解過此題
            </span>
        <?php endif; ?>
        <a href="/student/challenges" class="ctf-btn ctf-btn-ghost">
            <i class="bi bi-arrow-left"></i> 回到題目列表
        </a>
    </div>

    <div class="ctf-dash-hint" style="margin-top:1.5rem">
        <i class="bi bi-info-circle"></i> 啟動 Task 後，請到 Target Portal 貼上 Token 來開啟挑戰。
    </div>
</section>
