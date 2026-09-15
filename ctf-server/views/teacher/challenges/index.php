<?php
use CTF\Server\Security\CSRF;

/** @var array $challenges */
$challenges = $challenges ?? [];
?>
<section class="ctf-dash">
    <h1 class="ctf-dash-title">
        <i class="bi bi-collection"></i> 我的題目
    </h1>
    <div class="ctf-dash-rule"></div>
    <p class="ctf-dash-lead">管理你建立的題目。每個題目可以有多個版本，學生看到的會是最新發布的版本。</p>

    <div class="ctf-dash-actions">
        <a href="/teacher/challenges/new" class="ctf-btn ctf-btn-primary">
            <i class="bi bi-plus-circle"></i> 建立新題目
        </a>
    </div>

    <?php if (empty($challenges)): ?>
        <p class="ctf-muted">目前還沒有建立任何題目。點上方按鈕開始建立第一個題目。</p>
    <?php else: ?>
        <table class="ctf-table">
            <thead>
                <tr>
                    <th>題目</th>
                    <th>類別 / 難度</th>
                    <th>分數</th>
                    <th>版本</th>
                    <th>群組</th>
                    <th>狀態</th>
                    <th>操作</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($challenges as $ch): ?>
                    <tr>
                        <td>
                            <a href="/teacher/challenges/<?= (int)$ch['id'] ?>">
                                <strong><?= htmlspecialchars($ch['title'], ENT_QUOTES, 'UTF-8') ?></strong>
                            </a>
                            <div class="ctf-muted" style="font-size:14px;margin-top:4px;">
                                <?= htmlspecialchars($ch['slug'], ENT_QUOTES, 'UTF-8') ?>
                            </div>
                        </td>
                        <td>
                            <span class="ctf-tag"><?= htmlspecialchars($ch['category'], ENT_QUOTES, 'UTF-8') ?></span>
                            <?= htmlspecialchars($ch['difficulty'], ENT_QUOTES, 'UTF-8') ?>
                        </td>
                        <td class="ctf-mono"><?= (int)$ch['points'] ?></td>
                        <td class="ctf-mono">v<?= (int)$ch['version'] ?></td>
                        <td>
                            <?php if ((int)$ch['group_count'] === 0): ?>
                                <span class="ctf-muted">公開</span>
                            <?php else: ?>
                                <span class="ctf-tag"><?= (int)$ch['group_count'] ?> 個群組</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php
                            $statusColor = match ($ch['status']) {
                                'published' => 'var(--drafting-green)',
                                'disabled' => 'var(--drafting-red)',
                                default => 'var(--drafting-amber)',
                            };
                            $statusLabel = match ($ch['status']) {
                                'published' => '已發布',
                                'disabled' => '已下架',
                                default => '草稿',
                            };
                            ?>
                            <span class="ctf-tag" style="color:<?= $statusColor ?>;border-color:<?= $statusColor ?>">
                                <?= $statusLabel ?>
                            </span>
                        </td>
                        <td>
                            <a href="/teacher/challenges/<?= (int)$ch['id'] ?>" class="ctf-btn ctf-btn-sm">
                                <i class="bi bi-eye"></i> 管理
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>
