<?php
use CTF\Server\Security\CSRF;
/** @var array $old */
$old = $old ?? [];
?>
<section class="ctf-auth">
    <div class="ctf-auth-card">
        <h1 class="ctf-auth-title">重設密碼</h1>
        <div class="ctf-auth-rule"></div>
        <p class="ctf-auth-lead">
            輸入您的 <strong>帳號</strong> 或 <strong>Email</strong>，我們會寄送重設連結。
            連結僅在一段時間內有效。
        </p>

        <form action="/password/reset" method="post" class="ctf-form">
            <?= CSRF::field() ?>

            <label class="ctf-field">
                <span class="ctf-field-label">帳號或 Email</span>
                <input type="text" name="identifier" required maxlength="190"
                       value="<?= htmlspecialchars($old['identifier'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
            </label>

            <div class="ctf-form-actions">
                <button type="submit" class="ctf-btn ctf-btn-primary">[▸ 寄送重設連結]</button>
                <a href="/login" class="ctf-btn ctf-btn-ghost">[▸ 返回登入]</a>
            </div>
        </form>
    </div>
</section>
