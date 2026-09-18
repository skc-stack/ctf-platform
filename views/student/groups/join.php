<?php
use CTF\Server\Security\CSRF;
/** @var array $old */
/** @var string $prefillCode */
$old = $old ?? [];
$prefillCode = $prefillCode ?? '';
$codeValue = $old['join_code'] ?? $prefillCode;
?>
<section class="ctf-dash">
    <h1 class="ctf-dash-title">加入群組</h1>
    <div class="ctf-dash-rule"></div>
    <p class="ctf-dash-lead">向你的老師索取邀請碼（8 字元英數），輸入後即可加入。</p>

    <form action="/student/groups/join" method="post" class="ctf-form">
        <?= CSRF::field() ?>

        <label class="ctf-field">
            <span class="ctf-field-label">邀請碼 *</span>
            <input type="text" name="join_code" required maxlength="16" minlength="4"
                   pattern="[A-Z0-9]{4,16}"
                   placeholder="例如：ABC23XYZ"
                   style="text-transform:uppercase;letter-spacing:4px;font-family:var(--font-mono);font-size:24px;"
                   value="<?= htmlspecialchars($codeValue, ENT_QUOTES, 'UTF-8') ?>">
        </label>

        <div class="ctf-form-actions">
            <button type="submit" class="ctf-btn ctf-btn-primary">
                <i class="bi bi-box-arrow-in-right"></i> 加入
            </button>
            <a href="/student/groups" class="ctf-btn ctf-btn-ghost">
                <i class="bi bi-x-circle"></i> 取消
            </a>
        </div>
    </form>

    <p class="ctf-dash-hint"><i class="bi bi-question-circle"></i> 邀請碼不分大小寫。若你已離開或被踢出群組，無法再次加入。</p>
</section>
