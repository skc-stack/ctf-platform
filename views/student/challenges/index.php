<?php
use CTF\Server\Security\CSRF;

/** @var array $challenges */
/** @var array $pagination */
/** @var array $filters */
/** @var array $categories */
/** @var array $difficulties */
$challenges = $challenges ?? [];
$pagination = $pagination ?? ['page' => 1, 'total' => 0, 'perPage' => 10, 'pages' => 1];
$filters = $filters ?? ['category' => null, 'difficulty' => null];
$categories = $categories ?? [];
$difficulties = $difficulties ?? [];
?>
<section class="ctf-dash">
    <h1 class="ctf-dash-title">題目列表</h1>
    <div class="ctf-dash-rule"></div>
    <p class="ctf-dash-lead">所有可供解題的挑戰。</p>

    <!-- 過濾器 -->
    <form method="get" action="/student/challenges" style="margin-bottom:1rem">
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
            <label class="ctf-field" style="margin:0">
                <span class="ctf-field-label">類型</span>
                <select name="category" onchange="this.form.submit()" style="padding:4px 8px">
                    <option value="">全部</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') ?>"
                            <?= $filters['category'] === $cat ? 'selected' : '' ?>>
                            <?= htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="ctf-field" style="margin:0">
                <span class="ctf-field-label">難度</span>
                <select name="difficulty" onchange="this.form.submit()" style="padding:4px 8px">
                    <option value="">全部</option>
                    <?php foreach ($difficulties as $diff): ?>
                        <option value="<?= htmlspecialchars($diff, ENT_QUOTES, 'UTF-8') ?>"
                            <?= $filters['difficulty'] === $diff ? 'selected' : '' ?>>
                            <?= htmlspecialchars($diff, ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <a href="/student/challenges" class="ctf-btn ctf-btn-sm ctf-btn-ghost">清除過濾</a>
            <span class="ctf-muted" style="margin-left:auto">共 <?= (int)$pagination['total'] ?> 題</span>
        </div>
    </form>

    <?php if (empty($challenges)): ?>
        <p class="ctf-muted">目前沒有符合條件的題目。</p>
    <?php else: ?>
        <table class="ctf-table">
            <thead>
                <tr>
                    <th>題目</th>
                    <th>類別 / 難度</th>
                    <th>分數</th>
                    <th>解題人數</th>
                    <th>我的狀態</th>
                    <th>操作</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($challenges as $ch): ?>
                <tr>
                    <td>
                        <a href="/student/challenges/<?= (int)$ch['id'] ?>" class="ctf-link">
                            <strong><?= htmlspecialchars($ch['title'], ENT_QUOTES, 'UTF-8') ?></strong>
                        </a>
                        <div class="ctf-muted" style="font-size:14px"><?= htmlspecialchars($ch['slug'], ENT_QUOTES, 'UTF-8') ?></div>
                    </td>
                    <td>
                        <span class="ctf-tag"><?= htmlspecialchars($ch['category'], ENT_QUOTES, 'UTF-8') ?></span>
                        <?= htmlspecialchars($ch['difficulty'], ENT_QUOTES, 'UTF-8') ?>
                    </td>
                    <td class="ctf-mono"><?= (int)$ch['points'] ?></td>
                    <td class="ctf-mono"><?= (int)($ch['solve_count'] ?? 0) ?></td>
                    <td>
                        <?php if ((int)($ch['solved_by_me'] ?? 0) === 1): ?>
                            <span class="ctf-tag ctf-tag-success"><i class="bi bi-check-circle-fill"></i> 已解</span>
                        <?php else: ?>
                            <span class="ctf-tag">未解</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="/student/challenges/<?= (int)$ch['id'] ?>" class="ctf-btn ctf-btn-sm ctf-btn-primary">
                            <i class="bi bi-eye"></i> 查看詳情
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <!-- 分頁 -->
        <?php if ($pagination['pages'] > 1): ?>
            <nav class="ctf-pagination" style="margin-top:1rem;text-align:center">
                <?php
                $queryParams = [];
                if ($filters['category']) $queryParams['category'] = $filters['category'];
                if ($filters['difficulty']) $queryParams['difficulty'] = $filters['difficulty'];
                ?>
                <?php if ($pagination['page'] > 1): ?>
                    <a href="?<?= http_build_query(array_merge($queryParams, ['page' => $pagination['page'] - 1])) ?>"
                       class="ctf-btn ctf-btn-sm ctf-btn-ghost">‹ 上一頁</a>
                <?php endif; ?>
                <?php for ($p = 1; $p <= $pagination['pages']; $p++): ?>
                    <?php if ($p == 1 || $p == $pagination['pages'] || ($p >= $pagination['page'] - 2 && $p <= $pagination['page'] + 2)): ?>
                        <a href="?<?= http_build_query(array_merge($queryParams, ['page' => $p])) ?>"
                           class="ctf-btn ctf-btn-sm <?= $p === $pagination['page'] ? 'ctf-btn-primary' : 'ctf-btn-ghost' ?>">
                            <?= $p ?>
                        </a>
                    <?php elseif ($p == $pagination['page'] - 3 || $p == $pagination['page'] + 3): ?>
                        <span class="ctf-btn ctf-btn-sm" style="cursor:default">…</span>
                    <?php endif; ?>
                <?php endfor; ?>
                <?php if ($pagination['page'] < $pagination['pages']): ?>
                    <a href="?<?= http_build_query(array_merge($queryParams, ['page' => $pagination['page'] + 1])) ?>"
                       class="ctf-btn ctf-btn-sm ctf-btn-ghost">下一頁 ›</a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</section>
