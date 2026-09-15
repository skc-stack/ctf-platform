<?php
/**
 * @var string $title
 * @var string $prefill
 * @var ?string $error
 */
ob_start();
?>
<h1>啟用裝置</h1>
<p class="lead">把 Server 給你的啟用碼貼到下面。Server 會回傳 device_token，Agent 會把它存到 <code>/var/lib/ctf-agent/device.json</code>。</p>

<?php if ($error !== null): ?>
    <div class="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<div class="card">
    <form action="/activate" method="post" class="cform">
        <label>
            <span class="lbl">Activation Code</span>
            <input type="text" name="activation_code" required maxlength="32"
                   pattern="ACT-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}"
                   placeholder="ACT-XXXX-XXXX-XXXX"
                   style="text-transform:uppercase;letter-spacing:4px;font-size:20px"
                   value="<?= htmlspecialchars($prefill, ENT_QUOTES, 'UTF-8') ?>" autofocus>
        </label>
        <label>
            <span class="lbl">裝置名稱（選填）</span>
            <input type="text" name="device_name" maxlength="120" placeholder="例如：CS101-Target-01">
        </label>
        <div class="actions">
            <button type="submit" class="btn primary">啟用</button>
            <a href="/" class="btn ghost">取消</a>
        </div>
    </form>
</div>

<p class="hint">啟用碼 10 分鐘內有效，只能用一次。Server 端會把啟用碼 hash 起來存進 DB。</p>
<?php
$bodyHtml = ob_get_clean();
require __DIR__ . '/layout.php';
