<?php /** @var string $siteName */ /** @var string $env */ ?>
<section class="ctf-hero">
    <div class="ctf-hero-grid">
        <div>
            <p class="ctf-eyebrow">第 01 頁 · 中央控制台 · <?= htmlspecialchars(strtoupper($env), ENT_QUOTES, 'UTF-8') ?> 環境</p>
            <h1 class="ctf-hero-title">分散式資安<br>攻防演練平台</h1>
            <div class="ctf-hero-title-rule"></div>

            <p class="ctf-hero-lead">
                CTF LAB 是一套供學校學生使用的資安攻防演練平台。
                中央 Server 管理帳號、題目、裝置與分數；Target VM 為不可信任的執行端，
                所有 Flag 與分數都由 Server 自行決定。
            </p>

            <p class="ctf-hero-annotation">
                / 註記<br>
                信任邊界：CTF Server = 可信任，Target VM = 不可信任。
                分數與解題只能由 Server 授予，避免 Target 偽造。
            </p>

            <div class="ctf-hero-actions">
                <a href="/health" class="ctf-btn ctf-btn-primary">[▸ 健康檢查]</a>
                <a href="#spec" class="ctf-btn ctf-btn-ghost">[▢ 規格說明]</a>
                <a href="#sections" class="ctf-btn ctf-btn-ghost">[↓ 章節]</a>
                <a href="#disclaimer" class="ctf-btn ctf-btn-ghost">[⚠ 法律聲明]</a>
            </div>
        </div>

        <div class="ctf-terminal">
            <div class="ctf-terminal-bar">
                <span class="ctf-dot ctf-dot-red"></span>
                <span class="ctf-dot ctf-dot-amber"></span>
                <span class="ctf-dot ctf-dot-green"></span>
                <span class="ctf-terminal-title">~/ctf-server — 伺服器狀態</span>
            </div>
            <pre class="ctf-terminal-body"><span class="ctf-prompt">$</span> ctf-server status
<span class="ctf-ok">[ok]</span> APP_KEY=<span class="ctf-num">set</span>
<span class="ctf-ok">[ok]</span> FLAG_MASTER_SECRET=<span class="ctf-num">set</span>
<span class="ctf-ok">[ok]</span> DB=<span class="ctf-num">ctf_server</span>
<span class="ctf-ok">[ok]</span> php <?= htmlspecialchars(PHP_VERSION, ENT_QUOTES, 'UTF-8') ?>
<span class="ctf-ok">[ok]</span> trust boundary: <span class="ctf-str">server = trusted</span>

<span class="ctf-prompt">$</span> curl /health
{ <span class="ctf-str">"success"</span>: <span class="ctf-num">true</span>,
  <span class="ctf-str">"data"</span>: { <span class="ctf-str">"db"</span>: <span class="ctf-str">"ok"</span> } }</pre>
        </div>
    </div>
</section>

<section class="ctf-sections" id="sections">
    <div class="ctf-section">
        <div class="ctf-section-num">/ 01</div>
        <div class="ctf-section-title">中央控制台</div>
        <div class="ctf-section-desc">帳號、老師審核、題目 CRUD、發布、停用。所有狀態變更寫入 <code>audit_logs</code>。</div>
    </div>
    <div class="ctf-section">
        <div class="ctf-section-num">/ 02</div>
        <div class="ctf-section-title">裝置啟用</div>
        <div class="ctf-section-desc">學生可產生單次啟用碼（10 分鐘 TTL），Target VM 換得 Device Token；Server 只存 SHA-256 hash。</div>
    </div>
    <div class="ctf-section">
        <div class="ctf-section-num">/ 03</div>
        <div class="ctf-section-title">任務階段</div>
        <div class="ctf-section-desc">Task Token TTL 120 分鐘。Server 自行以 <code>HMAC-SHA256(student_id, challenge_uuid, task_uuid, FLAG_MASTER_SECRET)</code> 計算 Dynamic Flag。</div>
    </div>
    <div class="ctf-section">
        <div class="ctf-section-num">/ 04</div>
        <div class="ctf-section-title">計分與解題</div>
        <div class="ctf-section-desc">分數與首次解題只能由 Server 授予。Target 不得傳入 <code>student_id</code> 或 <code>points</code> 來覆寫。</div>
    </div>
</section>

<section class="ctf-sections" id="disclaimer" style="margin-top:3rem;padding-top:2rem;border-top:2px solid var(--border);">
    <div style="background:#fef3cd;border:1px solid #ffc107;border-radius:6px;padding:1rem 1.25rem;text-align:left;">
        <strong style="color:#856404">⚠️ 法律聲明與責任限制</strong>
        <ul style="margin:0.75rem 0 0 1.5rem;line-height:1.8;text-align:left;color:#856404;">
            <li>本平台僅供學校資訊安全教學與攻防演練使用。</li>
            <li>嚴禁利用本平台進行任何未經授權的網路攻擊、入侵或破壞行為。</li>
            <li>嚴禁以本平台作為攻擊校外系統或他人的工具。</li>
            <li>所有解題行為應於本平台題目範圍內進行，不得破解、竄改或繞過題目機制。</li>
            <li>使用本平台即表示同意遵守上述規範，並自行承擔相關責任。</li>
            <li>違規者將被停權，情節嚴重者將依法追究。</li>
        </ul>
    </div>
</section>
