<?php
use CTF\Server\Security\CSRF;
/** @var array $pending */
/** @var array $disabled */
$pending = $pending ?? [];
$disabled = $disabled ?? [];
?>
<section class="ctf-dash">
    <h1 class="ctf-dash-title">使用者審核</h1>
    <div class="ctf-dash-rule"></div>
    <p class="ctf-dash-lead">核准待審核的老師，或停用違規帳號。</p>

    <h2 class="ctf-dash-sub">/ 待審核</h2>
    <?php if (empty($pending)): ?>
        <p class="ctf-muted">目前沒有待審核的使用者。</p>
    <?php else: ?>
        <table class="ctf-table">
            <thead><tr><th>ID</th><th>帳號</th><th>顯示名稱</th><th>Email</th><th>建立時間</th><th>操作</th></tr></thead>
            <tbody>
            <?php foreach ($pending as $u): ?>
                <tr>
                    <td class="ctf-mono">#<?= (int)$u['id'] ?></td>
                    <td><i class="bi bi-person"></i> <?= htmlspecialchars($u['username'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($u['display_name'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td class="ctf-mono"><?= htmlspecialchars($u['email'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
                    <td class="ctf-mono"><?= htmlspecialchars($u['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td>
                        <form action="/admin/users/<?= (int)$u['id'] ?>/approve" method="post" style="display:inline">
                            <?= CSRF::field() ?>
                            <button type="submit" class="ctf-btn ctf-btn-sm ctf-btn-primary">[✓ 核准]</button>
                        </form>
                        <form action="/admin/users/<?= (int)$u['id'] ?>/disable" method="post" style="display:inline">
                            <?= CSRF::field() ?>
                            <button type="submit" class="ctf-btn ctf-btn-sm ctf-btn-ghost">[✗ 拒絕]</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <h2 class="ctf-dash-sub">/ 已停用</h2>
    <?php if (empty($disabled)): ?>
        <p class="ctf-muted">目前沒有被停用的使用者。</p>
    <?php else: ?>
        <table class="ctf-table">
            <thead><tr><th>ID</th><th>帳號</th><th>角色</th><th>Email</th></tr></thead>
            <tbody>
            <?php foreach ($disabled as $u): ?>
                <tr>
                    <td class="ctf-mono">#<?= (int)$u['id'] ?></td>
                    <td><i class="bi bi-person"></i> <?= htmlspecialchars($u['username'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><span class="ctf-tag"><?= htmlspecialchars($u['role'], ENT_QUOTES, 'UTF-8') ?></span></td>
                    <td class="ctf-mono"><?= htmlspecialchars($u['email'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>
