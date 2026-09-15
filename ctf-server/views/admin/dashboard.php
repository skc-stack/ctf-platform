<?php
/** @var array $stats */
/** @var array $recent_audit */
$stats = $stats ?? [];
?>
<section class="ctf-dash">
    <h1 class="ctf-dash-title">管理員儀表板</h1>
    <div class="ctf-dash-rule"></div>
    <p class="ctf-dash-lead">系統總覽與審核入口。</p>

    <div class="ctf-stat-grid">
        <div class="ctf-stat-card">
            <div class="ctf-stat-label"><i class="bi bi-people"></i> 活躍學生</div>
            <div class="ctf-stat-value ctf-stat-value-cyan"><?= (int)($stats['students'] ?? 0) ?></div>
            <div class="ctf-stat-meta">啟用中的學生帳號</div>
        </div>
        <div class="ctf-stat-card">
            <div class="ctf-stat-label"><i class="bi bi-person-workspace"></i> 活躍老師</div>
            <div class="ctf-stat-value ctf-stat-value-green"><?= (int)($stats['teachers'] ?? 0) ?></div>
            <div class="ctf-stat-meta">啟用中的老師帳號</div>
        </div>
        <div class="ctf-stat-card">
            <div class="ctf-stat-label"><i class="bi bi-hourglass"></i> 待審核</div>
            <div class="ctf-stat-value ctf-stat-value-amber"><?= (int)($stats['pending_teachers'] ?? 0) ?></div>
            <div class="ctf-stat-meta">老師申請待審</div>
        </div>
    </div>

    <div class="ctf-dash-actions">
        <a href="/admin/users" class="ctf-btn ctf-btn-primary">[▸ 前往使用者審核]</a>
    </div>

    <h2 class="ctf-dash-sub">/ 最近稽核日誌</h2>
    <?php if (empty($recent_audit)): ?>
        <p class="ctf-muted">尚無記錄。</p>
    <?php else: ?>
        <table class="ctf-table">
            <thead><tr><th>時間</th><th>使用者</th><th>動作</th><th>對象</th><th>IP</th></tr></thead>
            <tbody>
            <?php foreach ($recent_audit as $r): ?>
                <tr>
                    <td class="ctf-mono"><?= htmlspecialchars($r['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($r['username'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
                    <td><span class="ctf-tag"><?= htmlspecialchars($r['action'], ENT_QUOTES, 'UTF-8') ?></span></td>
                    <td class="ctf-mono"><?= htmlspecialchars(($r['target_type'] ?? '') . ' #' . ($r['target_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                    <td class="ctf-mono"><?= htmlspecialchars($r['ip'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>
