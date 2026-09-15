<?php
use CTF\Server\Security\CSRF;
/** @var string $token */
/** @var bool $valid */
$token = $token ?? '';
$valid = $valid ?? false;
?>
<section class="ctf-auth">
    <div class="ctf-auth-card">
        <h1 class="ctf-auth-title">重設密碼</h1>
        <div class="ctf-auth-rule"></div>

        <?php if (!$valid): ?>
            <p class="ctf-auth-lead">連結無效或已過期。</p>
            <div class="ctf-auth-notice ctf-auth-notice-warn">
                / 註記 連結只能用一次，且有時間限制。請重新申請一次重設信。
            </div>
            <div class="ctf-form-actions">
                <a href="/password/reset" class="ctf-btn ctf-btn-primary">[+ 重新申請連結]</a>
            </div>
        <?php else: ?>
            <p class="ctf-auth-lead">請輸入您的新密碼。密碼至少 8 字元，需含大小寫與數字。</p>

            <form action="/password/reset/confirm" method="post" class="ctf-form">
                <?= CSRF::field() ?>
                <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">

                <label class="ctf-field">
                    <span class="ctf-field-label">新密碼</span>
                    <div class="ctf-password-field">
                        <input type="password" name="password" id="reset-password" autocomplete="new-password" required minlength="8">
                        <button type="button" class="ctf-password-toggle" aria-label="顯示或隱藏密碼"
                                onclick="var p=document.getElementById('reset-password'); var s=p.type==='password'; p.type=s?'text':'password'; this.querySelector('i').className='bi '+(s?'bi-eye-slash':'bi-eye');">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                    <span class="ctf-field-hint">至少 8 字元、含大小寫與數字</span>
                </label>

                <label class="ctf-field">
                    <span class="ctf-field-label">確認新密碼</span>
                    <div class="ctf-password-field">
                        <input type="password" name="password_confirm" id="reset-password-confirm" autocomplete="new-password" required minlength="8">
                        <button type="button" class="ctf-password-toggle" aria-label="顯示或隱藏密碼"
                                onclick="var p=document.getElementById('reset-password-confirm'); var s=p.type==='password'; p.type=s?'text':'password'; this.querySelector('i').className='bi '+(s?'bi-eye-slash':'bi-eye');">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                </label>

                <div class="ctf-form-actions">
                    <button type="submit" class="ctf-btn ctf-btn-primary">[▸ 設定新密碼]</button>
                    <a href="/login" class="ctf-btn ctf-btn-ghost">[▸ 取消]</a>
                </div>
            </form>
        <?php endif; ?>
    </div>
</section>
