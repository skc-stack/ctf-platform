<?php
use CTF\Server\Security\CSRF;
/** @var array $groups */
$groups = $groups ?? [];
?>
<section class="ctf-dash">
    <h1 class="ctf-dash-title">我的群組</h1>
    <div class="ctf-dash-rule"></div>
    <p class="ctf-dash-lead">建立群組後把邀請碼分享給學生，學生加入後才能看到你發布的題目。</p>

    <div class="ctf-dash-actions">
        <a href="/teacher/groups/new" class="ctf-btn ctf-btn-primary">
            <i class="bi bi-plus-circle"></i> 建立新群組
        </a>
    </div>

    <?php if (empty($groups)): ?>
        <p class="ctf-muted">目前還沒有建立任何群組。點上方按鈕開始建立第一個群組。</p>
    <?php else: ?>
        <table class="ctf-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>名稱</th>
                    <th>邀請碼</th>
                    <th>人數</th>
                    <th>狀態</th>
                    <th>建立時間</th>
                    <th>操作</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($groups as $g): ?>
                    <tr>
                        <td class="ctf-mono">#<?= (int)$g['id'] ?></td>
                        <td>
                            <a href="/teacher/groups/<?= (int)$g['id'] ?>">
                                <i class="bi bi-people-fill"></i>
                                <?= htmlspecialchars($g['name'], ENT_QUOTES, 'UTF-8') ?>
                            </a>
                        </td>
                        <td class="ctf-mono" style="letter-spacing:2px"><?= htmlspecialchars($g['join_code'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="ctf-mono">
                            <?= (int)$g['member_count'] ?>
                            <?php if ($g['max_members'] !== null): ?>
                                / <?= (int)$g['max_members'] ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($g['status'] === 'active'): ?>
                                <span class="ctf-tag" style="color:var(--drafting-green);border-color:var(--drafting-green)">啟用</span>
                            <?php else: ?>
                                <span class="ctf-tag" style="color:var(--paper-mute)">已封存</span>
                            <?php endif; ?>
                        </td>
                        <td class="ctf-mono"><?= htmlspecialchars($g['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td>
                            <a href="/teacher/groups/<?= (int)$g['id'] ?>" class="ctf-btn ctf-btn-sm ctf-btn-ghost">
                                <i class="bi bi-eye"></i> 檢視
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>
