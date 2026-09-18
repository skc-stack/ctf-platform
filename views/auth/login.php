<?php
use CTF\Server\Security\CSRF;
/** @var array $old */
/** @var ?string $intended */
/** @var bool $locked */
/** @var int $lock_seconds */
$old = $old ?? [];
$locked = $locked ?? false;
$lock_seconds = $lock_seconds ?? 0;
?>
<section class="ctf-auth">
    <div class="ctf-auth-card">
        <h1 class="ctf-auth-title">登入</h1>
        <div class="ctf-auth-rule"></div>

        <?php if ($locked): ?>
            <?php $mins = (int)ceil($lock_seconds / 60); ?>
            <div class="ctf-auth-notice ctf-auth-notice-warn">
                <i class="bi bi-lock-fill"></i> 此瀏覽器已被鎖定，剩餘 <strong><?= $mins ?> 分鐘</strong>（<?= (int)$lock_seconds ?> 秒）後可再試。
                請清除瀏覽器 Cookie 或使用密碼重設信。
            </div>
        <?php else: ?>
            <p class="ctf-auth-lead">使用 CTF LAB 帳號登入以開始挑戰。</p>
        <?php endif; ?>

        <?php if (!empty($intended)): ?>
            <div class="ctf-auth-notice">/ 註記 登入後將回到 <code><?= htmlspecialchars($intended, ENT_QUOTES, 'UTF-8') ?></code></div>
        <?php endif; ?>

        <form action="/login" method="post" class="ctf-form" <?= $locked ? 'data-locked="true"' : '' ?>>
            <?= CSRF::field() ?>

            <div class="ctf-field-row">
                <label class="ctf-field">
                    <span class="ctf-field-label">帳號</span>
                    <input type="text" name="username" autocomplete="username" required minlength="3" maxlength="64"
                           value="<?= htmlspecialchars($old['username'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                           <?= $locked ? 'disabled' : '' ?>>
                </label>
                <label class="ctf-field">
                    <span class="ctf-field-label">密碼</span>
                    <div class="ctf-password-field">
                        <input type="password" name="password" id="login-password" autocomplete="current-password" required
                               <?= $locked ? 'disabled' : '' ?>>
                        <button type="button" class="ctf-password-toggle" aria-label="顯示或隱藏密碼"
                                onclick="var p=document.getElementById('login-password'); var s=p.type==='password'; p.type=s?'text':'password'; this.querySelector('i').className='bi '+(s?'bi-eye-slash':'bi-eye'); this.setAttribute('aria-label', s?'隱藏密碼':'顯示密碼');"
                                <?= $locked ? 'disabled' : '' ?>>
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                </label>
            </div>

            <label class="ctf-field">
                <span class="ctf-field-label">驗證碼</span>
                <div class="ctf-captcha-row">
                    <div class="ctf-captcha-img-wrap">
                        <img id="ctf-captcha-img" class="ctf-captcha-img" src="/captcha" alt="驗證碼" width="180" height="44">
                        <button type="button" class="ctf-captcha-refresh"
                                onclick="var i=document.getElementById('ctf-captcha-img'); i.src='/captcha?t='+Date.now();"
                                title="重新產生驗證碼"
                                aria-label="重新產生驗證碼">
                            <i class="bi bi-arrow-clockwise"></i>
                        </button>
                    </div>
                    <input type="text" name="captcha" required maxlength="6" minlength="4"
                           autocomplete="off" inputmode="latin"
                           placeholder="輸入圖中字元" <?= $locked ? 'disabled' : '' ?>>
                </div>
                <span class="ctf-field-hint">不區分大小寫 · 輸入 5 個字元 · [↻] 重新產生</span>
            </label>

            <div class="ctf-form-actions">
                <button type="submit" class="ctf-btn ctf-btn-primary" <?= $locked ? 'disabled' : '' ?>>[▸ 登入]</button>
                <a href="/register" class="ctf-btn ctf-btn-ghost" <?= $locked ? 'tabindex="-1"' : '' ?>>[+ 建立新帳號]</a>
                <a href="/password/reset" class="ctf-btn ctf-btn-ghost" <?= $locked ? 'tabindex="-1"' : '' ?>>[? 忘記密碼]</a>
            </div>
        </form>
    </div>
</section>
