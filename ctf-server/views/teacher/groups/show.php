<?php
use CTF\Server\Security\CSRF;
/** @var array $group */
/** @var array $members */
$group = $group ?? [];
$members = $members ?? [];
$created = $group['created_at'] ?? '';
$joinUrl = '/student/groups/join?code=' . urlencode((string)($group['join_code'] ?? ''));
?>
<section class="ctf-dash">
    <h1 class="ctf-dash-title">
        <i class="bi bi-people-fill"></i> <?= htmlspecialchars($group['name'] ?? '', ENT_QUOTES, 'UTF-8') ?>
    </h1>
    <div class="ctf-dash-rule"></div>
    <?php if (!empty($group['description'])): ?>
        <p class="ctf-dash-lead"><?= nl2br(htmlspecialchars($group['description'], ENT_QUOTES, 'UTF-8')) ?></p>
    <?php endif; ?>

    <div class="ctf-dash-meta">
        建立時間：<?= htmlspecialchars($created, ENT_QUOTES, 'UTF-8') ?>
        <?php if ($group['max_members'] !== null): ?>
            ｜ 上限：<?= (int)$group['max_members'] ?> 人
        <?php endif; ?>
    </div>

    <h2 class="ctf-dash-sub">/ 邀請碼</h2>
    <p>
        <span id="group-join-code" class="ctf-mono" style="font-size:32px;letter-spacing:6px;color:var(--brass);font-weight:700;">
            <?= htmlspecialchars($group['join_code'] ?? '', ENT_QUOTES, 'UTF-8') ?>
        </span>
        <button type="button" class="ctf-btn ctf-btn-sm" onclick="copyGroupValue('group-join-code', this)" title="複製邀請碼">
            <i class="bi bi-clipboard"></i> 複製
        </button>
    </p>
    <p class="ctf-muted">把邀請碼或下面網址分享給學生：</p>
    <p style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
        <span id="group-join-url" class="ctf-mono" style="flex:1;min-width:0;background:var(--ink);padding:10px;border:1px solid var(--grid);overflow-wrap:anywhere;">
            <?= htmlspecialchars($joinUrl, ENT_QUOTES, 'UTF-8') ?>
        </span>
        <button type="button" class="ctf-btn ctf-btn-sm" onclick="copyGroupValue('group-join-url', this)" title="複製邀請網址">
            <i class="bi bi-clipboard"></i> 複製網址
        </button>
    </p>
    <form action="/teacher/groups/<?= (int)$group['id'] ?>/regenerate-code" method="post" style="display:inline">
        <?= CSRF::field() ?>
        <button type="submit" class="ctf-btn ctf-btn-sm">
            <i class="bi bi-arrow-repeat"></i> 換新邀請碼
        </button>
    </form>

    <h2 class="ctf-dash-sub">/ 成員（<?= count($members) ?><?php if ($group['max_members'] !== null): ?> / <?= (int)$group['max_members'] ?><?php endif; ?>）</h2>
    <?php if (empty($members)): ?>
        <p class="ctf-muted">目前沒有成員。等學生輸入邀請碼加入後會列在這裡。</p>
    <?php else: ?>
        <table class="ctf-table">
            <thead>
                <tr>
                    <th>學生 ID</th>
                    <th>帳號</th>
                    <th>顯示名稱</th>
                    <th>Email</th>
                    <th>加入時間</th>
                    <th>操作</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($members as $m): ?>
                    <tr>
                        <td class="ctf-mono">#<?= (int)$m['student_id'] ?></td>
                        <td><i class="bi bi-person"></i> <?= htmlspecialchars($m['username'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($m['display_name'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="ctf-mono"><?= htmlspecialchars($m['email'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="ctf-mono"><?= htmlspecialchars($m['joined_at'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td>
                            <form action="/teacher/groups/<?= (int)$group['id'] ?>/remove/<?= (int)$m['student_id'] ?>" method="post" style="display:inline"
                                  onsubmit="return confirm('確定要將 <?= htmlspecialchars(addslashes($m['display_name']), ENT_QUOTES, 'UTF-8') ?> 從群組中移除？移除後該學生將無法再加入此群組。');">
                                <?= CSRF::field() ?>
                                <button type="submit" class="ctf-btn ctf-btn-sm ctf-btn-danger">
                                    <i class="bi bi-person-x"></i> 踢出
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <h2 class="ctf-dash-sub">/ 危險操作</h2>
    <form action="/teacher/groups/<?= (int)$group['id'] ?>/delete" method="post"
          onsubmit="return confirm('確定要刪除整個群組？所有成員關聯也會一併移除（成員帳號不受影響），此操作無法復原。');">
        <?= CSRF::field() ?>
        <button type="submit" class="ctf-btn ctf-btn-danger">
            <i class="bi bi-trash"></i> 刪除整個群組
        </button>
    </form>

    <p class="ctf-dash-hint"><i class="bi bi-arrow-left"></i> <a href="/teacher/groups">回到群組列表</a></p>
</section>

<script>
function copyGroupValue(srcId, btn) {
    var el = document.getElementById(srcId);
    if (!el) return;
    var text = el.textContent.trim();
    var done = function () {
        var original = btn.dataset.origHtml || btn.innerHTML;
        if (!btn.dataset.origHtml) btn.dataset.origHtml = original;
        btn.innerHTML = '<i class="bi bi-check2"></i> 已複製';
        btn.disabled = true;
        setTimeout(function () {
            btn.innerHTML = original;
            btn.disabled = false;
        }, 1500);
    };
    var fallback = function () {
        try {
            var ta = document.createElement('textarea');
            ta.value = text;
            ta.style.position = 'fixed';
            ta.style.opacity = '0';
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            document.body.removeChild(ta);
            done();
        } catch (e) {
            alert('複製失敗，請手動選取複製：\n' + text);
        }
    };
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text).then(done, fallback);
    } else {
        fallback();
    }
}
</script>
