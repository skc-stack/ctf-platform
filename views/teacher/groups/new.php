<?php
use CTF\Server\Security\CSRF;
/** @var array $old */
$old = $old ?? [];
?>
<section class="ctf-dash">
    <h1 class="ctf-dash-title">建立群組</h1>
    <div class="ctf-dash-rule"></div>
    <p class="ctf-dash-lead">群組建立後會自動產生 8 字元邀請碼。學生以此邀請碼加入群組。</p>

    <form action="/teacher/groups" method="post" class="ctf-form">
        <?= CSRF::field() ?>

        <label class="ctf-field">
            <span class="ctf-field-label">群組名稱 *</span>
            <input type="text" name="name" required maxlength="100"
                   placeholder="例：CS-101 網頁安全"
                   value="<?= htmlspecialchars($old['name'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
        </label>

        <label class="ctf-field">
            <span class="ctf-field-label">說明（選填）</span>
            <textarea name="description" maxlength="2000" rows="3"
                      placeholder="給學生看的群組描述，例如：上課時間、課程範圍…"><?= htmlspecialchars($old['description'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
        </label>

        <label class="ctf-field">
            <span class="ctf-field-label">人數上限（選填；留空 = 不限）</span>
            <input type="number" name="max_members" min="1" max="9999"
                   placeholder="例如：40"
                   value="<?= htmlspecialchars($old['max_members'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
        </label>

        <div class="ctf-form-actions">
            <button type="submit" class="ctf-btn ctf-btn-primary">
                <i class="bi bi-plus-circle"></i> 建立群組
            </button>
            <a href="/teacher/groups" class="ctf-btn ctf-btn-ghost">
                <i class="bi bi-x-circle"></i> 取消
            </a>
        </div>
    </form>
</section>
