<?php
/** @var array $rows */
$rows = $rows ?? [];
?>
<section class="ctf-dash">
    <h1 class="ctf-dash-title">排行榜</h1>
    <div class="ctf-dash-rule"></div>
    <p class="ctf-dash-lead">依分數與最後解題時間排序。前 100 名。</p>

    <?php if (empty($rows)): ?>
        <p class="ctf-muted">目前還沒有任何學生上榜。</p>
    <?php else: ?>
        <table class="ctf-table ctf-lb-table">
            <thead>
                <tr>
                    <th>名次</th>
                    <th>帳號</th>
                    <th>顯示名稱</th>
                    <th>班級</th>
                    <th>已解題</th>
                    <th>分數</th>
                    <th>上次解題</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $i => $r): ?>
                <tr>
                    <td><?= $i + 1 ?></td>
                    <td><?= htmlspecialchars($r['username'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($r['display_name'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($r['email'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
                    <td class="ctf-mono"><?= (int)$r['solved_count'] ?></td>
                    <td class="ctf-mono"><?= (int)$r['score'] ?></td>
                    <td class="ctf-mono"><?= htmlspecialchars($r['last_solve'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>
