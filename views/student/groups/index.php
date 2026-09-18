<?php
/** @var array $groups */
$groups = $groups ?? [];
?>
<section class="ctf-dash">
    <h1 class="ctf-dash-title">我的群組</h1>
    <div class="ctf-dash-rule"></div>
    <p class="ctf-dash-lead">你加入的群組。加入群組才能看到該老師發布的題目。</p>

    <div class="ctf-dash-actions">
        <a href="/student/groups/join" class="ctf-btn ctf-btn-primary">
            <i class="bi bi-plus-circle"></i> 輸入邀請碼加入
        </a>
    </div>

    <?php if (empty($groups)): ?>
        <p class="ctf-muted">尚未加入任何群組。請向你的老師索取邀請碼。</p>
    <?php else: ?>
        <table class="ctf-table">
            <thead>
                <tr>
                    <th>群組</th>
                    <th>老師</th>
                    <th>人數</th>
                    <th>加入時間</th>
                    <th>操作</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($groups as $g): ?>
                    <tr>
                        <td>
                            <i class="bi bi-people-fill"></i>
                            <strong><?= htmlspecialchars($g['name'], ENT_QUOTES, 'UTF-8') ?></strong>
                            <?php if (!empty($g['description'])): ?>
                                <div class="ctf-muted" style="margin-top:4px"><?= htmlspecialchars($g['description'], ENT_QUOTES, 'UTF-8') ?></div>
                            <?php endif; ?>
                        </td>
                        <td><i class="bi bi-person-workspace"></i> <?= htmlspecialchars($g['teacher_name'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="ctf-mono"><?= (int)$g['member_count'] ?></td>
                        <td class="ctf-mono"><?= htmlspecialchars($g['joined_at'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td>
                            <form action="/student/groups/<?= (int)$g['id'] ?>/leave" method="post" style="display:inline"
                                  onsubmit="return confirm('確定要離開「<?= htmlspecialchars(addslashes($g['name']), ENT_QUOTES, 'UTF-8') ?>」？離開後可能看不到該老師的題目。');">
                                <?= \CTF\Server\Security\CSRF::field() ?>
                                <button type="submit" class="ctf-btn ctf-btn-sm ctf-btn-danger">
                                    <i class="bi bi-box-arrow-right"></i> 離開
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>
