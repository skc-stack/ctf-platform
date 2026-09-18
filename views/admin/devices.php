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
                    <th>已同步</th>
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
                            <?php
                            $syncCount = (int)($d['synced_challenges_count'] ?? 0);
                            $lastSync = $d['last_synced_at'] ?? null;
                            ?>
                            <?php if ($syncCount > 0): ?>
                                <span class="ctf-tag ctf-tag-success"><?= $syncCount ?> 題</span>
                                <?php if ($lastSync): $syncTime = date('m/d H:i', strtotime($lastSync)); ?>
                                <br><small class="ctf-muted"><?= $syncTime ?></small>
                                <?php endif; ?>
                                <br><a href="#" class="ctf-link ctf-link-sm" onclick="toggleSyncList(this, <?= (int)$d['id'] ?>); return false;">[詳情]</a>
                                <div id="sync-list-<?= (int)$d['id'] ?>" class="sync-list" style="display:none;margin-top:8px"></div>
                            <?php else: ?>
                                <span class="ctf-muted">未同步</span>
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

<script>
function toggleSyncList(link, deviceId) {
    var div = document.getElementById('sync-list-' + deviceId);
    if (div.style.display === 'none') {
        fetch('/admin/devices/' + deviceId + '/sync')
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success && data.challenges) {
                    var html = '<table class="ctf-table" style="font-size:12px"><thead><tr><th>題目</th><th>版本</th><th>SHA-256</th><th>同步時間</th></tr></thead><tbody>';
                    if (data.challenges.length === 0) {
                        html += '<tr><td colspan="4" class="ctf-muted">尚無同步資料</td></tr>';
                    } else {
                        data.challenges.forEach(function(ch) {
                            html += '<tr>';
                            html += '<td>' + (ch.challenge_title || ch.challenge_id) + '</td>';
                            html += '<td class="ctf-mono">v' + ch.challenge_version + '</td>';
                            html += '<td class="ctf-mono" style="font-size:10px">' + (ch.sha256 || '-').substring(0, 12) + '...</td>';
                            html += '<td class="ctf-mono">' + formatDate(ch.synced_at) + '</td>';
                            html += '</tr>';
                        });
                    }
                    html += '</tbody></table>';
                    div.innerHTML = html;
                } else {
                    div.innerHTML = '<span class="ctf-muted">載入失敗</span>';
                }
            })
            .catch(function() {
                div.innerHTML = '<span class="ctf-muted">載入失敗</span>';
            });
        div.style.display = 'block';
        link.textContent = '[隱藏]';
    } else {
        div.style.display = 'none';
        link.textContent = '[詳情]';
    }
    return false;
}

function formatDate(dateStr) {
    if (!dateStr) return '-';
    var d = new Date(dateStr);
    var m = ('0' + (d.getMonth() + 1)).slice(-2);
    var day = ('0' + d.getDate()).slice(-2);
    var h = ('0' + d.getHours()).slice(-2);
    var min = ('0' + d.getMinutes()).slice(-2);
    return m + '/' + day + ' ' + h + ':' + min;
}
</script>
