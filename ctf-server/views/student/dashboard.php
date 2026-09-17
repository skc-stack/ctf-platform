<?php
use CTF\Server\Security\CSRF;

/** @var array $stats */
/** @var ?string $last_solve */
/** @var array $challenges */
/** @var array $active_tasks_list */
$stats = $stats ?? ['total_score' => 0, 'rank' => 0, 'solved' => 0, 'active_tasks' => 0];
$challenges = $challenges ?? [];
$active_tasks_list = $active_tasks_list ?? [];
?>
<section class="ctf-dash">
    <h1 class="ctf-dash-title">學生儀表板</h1>
    <div class="ctf-dash-rule"></div>
    <p class="ctf-dash-lead">你的整體進度。所有分數皆由 Server 決定。</p>

    <div class="ctf-stat-grid">
        <div class="ctf-stat-card">
            <div class="ctf-stat-label"><i class="bi bi-coin"></i> 總分</div>
            <div class="ctf-stat-value ctf-stat-value-cyan"><?= (int)$stats['total_score'] ?></div>
            <div class="ctf-stat-meta">累計得分</div>
        </div>
        <div class="ctf-stat-card">
            <div class="ctf-stat-label"><i class="bi bi-trophy"></i> 排名</div>
            <div class="ctf-stat-value ctf-stat-value-green">#<?= (int)$stats['rank'] ?></div>
            <div class="ctf-stat-meta">目前名次</div>
        </div>
        <div class="ctf-stat-card">
            <div class="ctf-stat-label"><i class="bi bi-check2-square"></i> 已解題</div>
            <div class="ctf-stat-value ctf-stat-value-purple"><?= (int)$stats['solved'] ?></div>
            <div class="ctf-stat-meta">解題數量</div>
        </div>
        <div class="ctf-stat-card">
            <div class="ctf-stat-label"><i class="bi bi-hourglass-split"></i> 進行中任務</div>
            <div class="ctf-stat-value ctf-stat-value-amber"><?= (int)$stats['active_tasks'] ?></div>
            <div class="ctf-stat-meta">尚未完成</div>
        </div>
    </div>

    <?php if ($last_solve): ?>
        <p class="ctf-dash-meta"><i class="bi bi-clock-history"></i> 上次解題時間：<?= htmlspecialchars($last_solve, ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>

    <div class="ctf-dash-actions">
        <a href="/student/devices" class="ctf-btn ctf-btn-primary">[▸ 我的裝置 / Activation Code]</a>
    </div>

    <?php if (!empty($active_tasks_list)): ?>
    <h2 class="ctf-dash-sub">/ 進行中的 Task</h2>
    <table class="ctf-table">
        <thead><tr><th>題目</th><th>開始</th><th>過期</th><th>狀態</th><th>操作</th></tr></thead>
        <tbody>
        <?php foreach ($active_tasks_list as $t): ?>
            <tr>
                <td><i class="bi bi-terminal"></i> <?= htmlspecialchars($t['challenge_title'], ENT_QUOTES, 'UTF-8') ?></td>
                <td class="ctf-mono"><?= htmlspecialchars($t['started_at'], ENT_QUOTES, 'UTF-8') ?></td>
                <td class="ctf-mono"><?= htmlspecialchars($t['expires_at'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><span class="ctf-tag" style="color:var(--drafting-green);border-color:var(--drafting-green)">active</span></td>
                <td><a href="/student/task/<?= (int)$t['id'] ?>" class="ctf-btn ctf-btn-sm ctf-btn-primary"><i class="bi bi-arrow-right"></i> 開啟</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <h2 class="ctf-dash-sub">/ 題目</h2>
    <?php if (empty($challenges)): ?>
        <p class="ctf-muted">目前沒有可解的題目。請等待老師發布，或確認你已加入對應的群組。</p>
    <?php else: ?>
        <table class="ctf-table">
            <thead>
                <tr>
                    <th>題目</th>
                    <th>類別 / 難度</th>
                    <th>分數</th>
                    <th>已解過</th>
                    <th>操作</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($challenges as $ch): ?>
                <tr>
                    <td>
                        <strong><?= htmlspecialchars($ch['title'], ENT_QUOTES, 'UTF-8') ?></strong>
                        <div class="ctf-muted" style="font-size:14px"><?= htmlspecialchars($ch['slug'], ENT_QUOTES, 'UTF-8') ?></div>
                    </td>
                    <td>
                        <span class="ctf-tag"><?= htmlspecialchars($ch['category'], ENT_QUOTES, 'UTF-8') ?></span>
                        <?= htmlspecialchars($ch['difficulty'], ENT_QUOTES, 'UTF-8') ?>
                    </td>
                    <td class="ctf-mono"><?= (int)$ch['points'] ?></td>
                    <td>
                        <?php if ((int)$ch['solved_by_me'] === 1): ?>
                            <i class="bi bi-check-circle-fill" style="color:var(--drafting-green)"></i> 已解
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ((int)$ch['solved_by_me'] === 1): ?>
                            <span class="ctf-muted">已完成</span>
                        <?php else: ?>
                            <form action="/api/v1/student/task/start" method="post" style="display:inline">
                                <?= CSRF::field() ?>
                                <input type="hidden" name="challenge_id" value="<?= (int)$ch['id'] ?>">
                                <button type="submit" class="ctf-btn ctf-btn-sm ctf-btn-primary">
                                    <i class="bi bi-play-fill"></i> 啟動 Task
                                </button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>
