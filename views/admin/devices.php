<?php
/** @var array $devices */
/** @var array $pagination */
/** @var array $activation_codes */
$devices = $devices ?? [];
$pagination = $pagination ?? ['page' => 1, 'total' => 0, 'perPage' => 20, 'pages' => 1];
$activation_codes = $activation_codes ?? [];
?>
<section class="ctf-dash">
    <h1 class="ctf-dash-title">裝置管理</h1>
    <div class="ctf-dash-rule"></div>
    <p class="ctf-dash-lead">管理所有已激活的 Target VM 裝置與 Activation Codes。</p>

    <!-- 裝置列表 -->
    <h2 class="ctf-dash-sub">/ 已註冊裝置 (<?= (int)$pagination['total'] ?>)</h2>
    <?php if (empty($devices)): ?>
        <p class="ctf-muted">目前沒有已註冊的裝置。</p>
    <?php else: ?>
        <table class="ctf-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>裝置名稱</th>
                    <th>擁有者</th>
                    <th>UUID</th>
                    <th>狀態</th>
                    <th>Agent 版本</th>
                    <th>在線/持續</th>
                    <th>目前題目</th>
                    <th>操作</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($devices as $d): ?>
                    <tr>
                        <td class="ctf-mono">#<?= (int)$d['id'] ?></td>
                        <td><?= htmlspecialchars($d['name'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($d['user_display_name'] ?? $d['user_username'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="ctf-mono" style="font-size:0.85em"><?= htmlspecialchars(substr($d['uuid'], 0, 12), ENT_QUOTES, 'UTF-8') ?>…</td>
                        <td>
                            <?php if ($d['status'] === 'active'): ?>
                                <span class="ctf-tag ctf-tag-success">線上</span>
                            <?php elseif ($d['status'] === 'revoked'): ?>
                                <span class="ctf-tag ctf-tag-danger">已撤銷</span>
                            <?php else: ?>
                                <span class="ctf-tag"><?= htmlspecialchars($d['status'], ENT_QUOTES, 'UTF-8') ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="ctf-mono"><?= htmlspecialchars($d['agent_version'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="ctf-mono"><?= htmlspecialchars($d['last_seen_at'] ?? '從未', ENT_QUOTES, 'UTF-8') ?>
                            <?php
                            // Calculate online duration
                            if (!empty($d['last_seen_at'])) {
                                $lastSeen = strtotime($d['last_seen_at']);
                                $now = time();
                                $diff = $now - $lastSeen;
                                if ($diff < 60) {
                                    $dur = $diff . '秒';
                                } elseif ($diff < 3600) {
                                    $dur = floor($diff / 60) . '分';
                                } elseif ($diff < 86400) {
                                    $dur = floor($diff / 3600) . '時';
                                } else {
                                    $dur = floor($diff / 86400) . '天';
                                }
                                echo ' (' . $dur . ')';
                            }
                            ?>
                        </td>
                        <td>
                            <?php if (!empty($d['task_uuid'])): ?>
                                <span class="ctf-tag ctf-tag-success"><?= htmlspecialchars($d['challenge_title'] ?? $d['task_uuid'], ENT_QUOTES, 'UTF-8') ?></span>
                                <br><small class="ctf-muted"><?= htmlspecialchars(date('H:i', strtotime($d['task_started_at']))) ?> ~ <?= htmlspecialchars(date('H:i', strtotime($d['task_expires_at']))) ?></small>
                            <?php else: ?>
                                <span class="ctf-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($d['status'] !== 'revoked'): ?>
                                <form action="/admin/devices/<?= (int)$d['id'] ?>/revoke" method="post" style="display:inline"
                                      onsubmit="return confirm('確定要撤銷此裝置？');">
                                    <?= \CTF\Server\Security\CSRF::field() ?>
                                    <button type="submit" class="ctf-btn ctf-btn-sm ctf-btn-ghost">[撤銷]</button>
                                </form>
                            <?php else: ?>
                                <span class="ctf-muted">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <!-- 分頁 -->
        <?php if ($pagination['pages'] > 1): ?>
            <nav class="ctf-pagination" style="margin-top:1rem;text-align:center">
                <?php for ($p = 1; $p <= $pagination['pages']; $p++): ?>
                    <a href="?page=<?= $p ?>"
                       class="ctf-btn ctf-btn-sm <?= $p === $pagination['page'] ? 'ctf-btn-primary' : 'ctf-btn-ghost' ?>"
                       style="margin:0 0.25rem"><?= $p ?></a>
                <?php endfor; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>

    <!-- Activation Codes -->
    <h2 class="ctf-dash-sub" style="margin-top:2rem">/ Activation Codes</h2>
    <?php if (empty($activation_codes)): ?>
        <p class="ctf-muted">目前沒有 Activation Codes。</p>
    <?php else: ?>
        <table class="ctf-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>建立者</th>
                    <th>建立時間</th>
                    <th>過期時間</th>
                    <th>已使用</th>
                    <th>操作</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($activation_codes as $c): ?>
                    <tr>
                        <td class="ctf-mono">#<?= (int)$c['id'] ?></td>
                        <td><?= htmlspecialchars($c['user_display_name'] ?? $c['user_username'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="ctf-mono"><?= htmlspecialchars($c['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="ctf-mono"><?= htmlspecialchars($c['expires_at'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td>
                            <?php if ($c['used_at']): ?>
                                <span class="ctf-tag ctf-tag-success">是</span>
                            <?php else: ?>
                                <span class="ctf-tag ctf-tag-warning">否</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <form action="/admin/devices/codes/<?= (int)$c['id'] ?>" method="post" style="display:inline"
                                  onsubmit="return confirm('確定要刪除這個 Activation Code？');">
                                <?= \CTF\Server\Security\CSRF::field() ?>
                                <button type="submit" class="ctf-btn ctf-btn-sm ctf-btn-ghost">[刪除]</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>
