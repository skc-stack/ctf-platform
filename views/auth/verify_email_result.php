<?php
/** @var bool $ok */
/** @var ?string $reason */
/** @var ?string $user_role */
/** @var ?string $user_status */

$ok = $ok ?? false;
$reason = $reason ?? null;
$role = $user_role ?? null;
$status = $user_status ?? null;
?>
<section class="ctf-auth">
    <div class="ctf-auth-card">
        <?php if ($ok): ?>
            <h1 class="ctf-auth-title"><i class="bi bi-check-circle"></i> 信箱驗證成功</h1>
            <div class="ctf-auth-rule"></div>
            <p class="ctf-auth-lead">您的 Email 已通過驗證。</p>

            <?php if ($role === 'student'): ?>
                <div class="ctf-auth-notice">
                    / 註記 學生帳號已自動啟用（<code>狀態 = 啟用中</code>），現在可以登入。
                </div>
            <?php elseif ($role === 'teacher'): ?>
                <div class="ctf-auth-notice ctf-auth-notice-warn">
                    / 註記 老師帳號 Email 已驗證，但狀態仍為 <code>待審核</code>，需待管理員審核通過才能登入。
                </div>
            <?php endif; ?>

            <div class="ctf-form-actions">
                <a href="/login" class="ctf-btn ctf-btn-primary">[▸ 前往登入]</a>
            </div>
        <?php else: ?>
            <h1 class="ctf-auth-title" style="background:linear-gradient(135deg,#D8553A,#C9A961);-webkit-background-clip:text;background-clip:text;color:transparent;">✗ 驗證失敗</h1>
            <div class="ctf-auth-rule"></div>
            <p class="ctf-auth-lead">Email 驗證連結無效或已過期。</p>

            <?php if ($reason === 'missing_token'): ?>
                <div class="ctf-auth-notice ctf-auth-notice-warn">/ 註記 連結缺少 token 參數</div>
            <?php else: ?>
                <div class="ctf-auth-notice ctf-auth-notice-warn">/ 註記 token 無效、過期或已被使用。每個連結只能用一次。</div>
            <?php endif; ?>

            <p class="ctf-auth-lead" style="font-family:var(--font-mono);font-size:14px;color:var(--paper-mute);">
                如果連結已過期，您可以重新註冊或請管理員重發驗證信。
            </p>

            <div class="ctf-form-actions">
                <a href="/register" class="ctf-btn ctf-btn-primary">[+ 重新註冊]</a>
                <a href="/login" class="ctf-btn ctf-btn-ghost">[▸ 前往登入]</a>
            </div>
        <?php endif; ?>
    </div>
</section>
