<?php
/** @var array $stats */
$stats = $stats ?? ['draft' => 0, 'published' => 0, 'disabled' => 0];
?>
<section class="ctf-dash">
    <h1 class="ctf-dash-title">老師儀表板</h1>
    <div class="ctf-dash-rule"></div>
    <p class="ctf-dash-lead">管理你建立的題目。§2 之後可在這裡上傳 ZIP 與發布題目。</p>

    <div class="ctf-stat-grid">
        <div class="ctf-stat-card">
            <div class="ctf-stat-label"><i class="bi bi-pencil-square"></i> 草稿</div>
            <div class="ctf-stat-value ctf-stat-value-amber"><?= (int)$stats['draft'] ?></div>
            <div class="ctf-stat-meta">尚未發布</div>
        </div>
        <div class="ctf-stat-card">
            <div class="ctf-stat-label"><i class="bi bi-rocket-takeoff"></i> 已發布</div>
            <div class="ctf-stat-value ctf-stat-value-green"><?= (int)$stats['published'] ?></div>
            <div class="ctf-stat-meta">學生可解</div>
        </div>
        <div class="ctf-stat-card">
            <div class="ctf-stat-label"><i class="bi bi-archive"></i> 已下架</div>
            <div class="ctf-stat-value ctf-stat-value-cyan"><?= (int)$stats['disabled'] ?></div>
            <div class="ctf-stat-meta">暫停使用</div>
        </div>
    </div>

    <p class="ctf-dash-hint"><i class="bi bi-info-circle"></i> §2 之後將顯示「我的題目」清單與「建立題目」入口。</p>
</section>
