<?php
use CTF\Server\Security\CSRF;

/** @var array $old */
$old = $old ?? [];

$role = (string)($_GET['role'] ?? $old['_role'] ?? 'student');
if (!in_array($role, ['student', 'teacher'], true)) {
    $role = 'student';
}
$isStudent = $role === 'student';
$isTeacher = $role === 'teacher';
?>
<section class="ctf-auth">
    <div class="ctf-auth-card">
        <h1 class="ctf-auth-title">註冊</h1>
        <div class="ctf-auth-rule"></div>
        <p class="ctf-auth-lead">
            學生註冊後立即生效；老師註冊後狀態為待審核，需管理員核准。
        </p>

        <div class="ctf-role-tabs" role="tablist">
            <a href="?role=student"
               class="ctf-role-tab <?= $isStudent ? 'is-active' : '' ?>"
               role="tab" aria-selected="<?= $isStudent ? 'true' : 'false' ?>">
                <i class="bi bi-mortarboard"></i> 我是學生
            </a>
            <a href="?role=teacher"
               class="ctf-role-tab <?= $isTeacher ? 'is-active' : '' ?>"
               role="tab" aria-selected="<?= $isTeacher ? 'true' : 'false' ?>">
                <i class="bi bi-person-workspace"></i> 我是老師
            </a>
        </div>

        <?php if ($isTeacher): ?>
            <div class="ctf-auth-notice ctf-auth-notice-warn">
                <i class="bi bi-info-circle"></i> 老師帳號預設狀態為 <code>待審核</code>，需管理員於「使用者審核」核准後才能登入。
            </div>
        <?php endif; ?>

        <form action="/register" method="post" class="ctf-form">
            <?= CSRF::field() ?>
            <input type="hidden" name="_role" value="<?= htmlspecialchars($role, ENT_QUOTES, 'UTF-8') ?>">

            <div class="ctf-field-row">
                <label class="ctf-field">
                    <span class="ctf-field-label">帳號 *</span>
                    <input type="text" name="username" required minlength="3" maxlength="64"
                           pattern="[A-Za-z0-9_.\-]+"
                           value="<?= htmlspecialchars($old['username'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                </label>
                <label class="ctf-field">
                    <span class="ctf-field-label">顯示名稱 *</span>
                    <input type="text" name="display_name" required maxlength="100"
                           value="<?= htmlspecialchars($old['display_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                </label>
            </div>

            <label class="ctf-field">
                <span class="ctf-field-label">Email *</span>
                <input type="email" name="email" maxlength="190" required
                       value="<?= htmlspecialchars($old['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
            </label>

            <div class="ctf-field-row">
                <label class="ctf-field">
                    <span class="ctf-field-label">密碼 *</span>
                    <div class="ctf-password-field">
                        <input type="password" name="password" id="register-password" autocomplete="new-password" required minlength="8"
                               oninput="pwCheckStrength(this.value); pwCheckMatch();">
                        <button type="button" class="ctf-password-toggle" aria-label="顯示或隱藏密碼"
                                onclick="var p=document.getElementById('register-password'); var s=p.type==='password'; p.type=s?'text':'password'; this.querySelector('i').className='bi '+(s?'bi-eye-slash':'bi-eye');">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                    <div class="ctf-password-meter" id="pw-meter">
                        <div class="ctf-pw-strength-row">
                            <span>密碼強度</span>
                            <span class="ctf-pw-strength-label" id="pw-strength-label">—</span>
                        </div>
                        <div class="ctf-pw-strength"><span class="ctf-pw-bar"></span></div>
                        <ul class="ctf-pw-rules">
                            <li data-rule="length">至少 8 字元</li>
                            <li data-rule="lower">含小寫字母</li>
                            <li data-rule="upper">含大寫字母</li>
                            <li data-rule="digit">含數字</li>
                        </ul>
                    </div>
                </label>
                <label class="ctf-field">
                    <span class="ctf-field-label">確認密碼 *</span>
                    <div class="ctf-password-field">
                        <input type="password" name="password_confirm" id="register-password-confirm" autocomplete="new-password" required minlength="8"
                               oninput="pwCheckMatch();">
                        <button type="button" class="ctf-password-toggle" aria-label="顯示或隱藏密碼"
                                onclick="var p=document.getElementById('register-password-confirm'); var s=p.type==='password'; p.type=s?'text':'password'; this.querySelector('i').className='bi '+(s?'bi-eye-slash':'bi-eye');">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                    <div class="ctf-pw-match" id="pw-match"></div>
                </label>
            </div>

            <div class="ctf-form-actions">
                <button type="submit" class="ctf-btn ctf-btn-primary">
                    <i class="bi bi-<?= $isTeacher ? 'send-check' : 'person-plus' ?>"></i>
                    <?= $isTeacher ? '送出審核申請' : '建立學生帳號' ?>
                </button>
                <a href="/login" class="ctf-btn ctf-btn-ghost"><i class="bi bi-box-arrow-in-right"></i> 已有帳號？登入</a>
            </div>
        </form>
    </div>
</section>

<script>
function pwScore(pw) {
    var rules = {
        length: pw.length >= 8,
        lower:   /[a-z]/.test(pw),
        upper:   /[A-Z]/.test(pw),
        digit:   /\d/.test(pw),
    };
    var bonus = 0;
    if (pw.length >= 12) bonus++;
    if (/[^A-Za-z0-9]/.test(pw)) bonus++;
    var passed = 0;
    for (var k in rules) if (rules[k]) passed++;
    return { rules: rules, score: passed + bonus };
}
function pwCheckStrength(pw) {
    var r = pwScore(pw || '');
    var lis = document.querySelectorAll('#pw-meter li[data-rule]');
    for (var i = 0; i < lis.length; i++) {
        lis[i].dataset.ok = r.rules[lis[i].dataset.rule] ? '1' : '0';
    }
    var label = '—', level = '';
    if (r.score >= 5)      { label = '強'; level = 'strong'; }
    else if (r.score >= 3) { label = '中等'; level = 'medium'; }
    else if (r.score >= 1) { label = '弱'; level = 'weak'; }
    var meter = document.getElementById('pw-meter');
    if (meter) meter.dataset.strength = level;
    var lbl = document.getElementById('pw-strength-label');
    if (lbl) lbl.textContent = label;
}
function pwCheckMatch() {
    var pw = (document.getElementById('register-password') || {}).value || '';
    var cf = (document.getElementById('register-password-confirm') || {}).value || '';
    var el = document.getElementById('pw-match');
    if (!el) return;
    if (!cf) { el.textContent = ''; el.dataset.match = ''; return; }
    if (pw === cf) {
        el.textContent = '✓ 密碼相符';
        el.dataset.match = 'ok';
    } else {
        el.textContent = '✗ 密碼不相符';
        el.dataset.match = 'fail';
    }
}
</script>
