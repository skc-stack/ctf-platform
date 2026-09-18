<?php
use CTF\Server\Security\CSRF;
use CTF\Server\Support\Config;

/** @var array $challenge */
/** @var array $packages */
/** @var ?array $manifest */
/** @var array $groupBindings */
/** @var array $myGroups */
/** @var ?string $error */
/** @var ?string $success */
$challenge = $challenge ?? [];
$id = (int)($challenge['id'] ?? 0);
$slug = $challenge['slug'] ?? '';
$packages = $packages ?? [];
$groupBindings = $groupBindings ?? [];
$myGroups = $myGroups ?? [];
$error = $error ?? null;
$success = $success ?? null;
?>
<section class="ctf-dash">
    <h1 class="ctf-dash-title">
        <i class="bi bi-collection"></i> <?= htmlspecialchars($challenge['title'] ?? '', ENT_QUOTES, 'UTF-8') ?>
    </h1>
    <div class="ctf-dash-rule"></div>

    <div class="ctf-dash-meta">
        <i class="bi bi-tag"></i> <code><?= htmlspecialchars($challenge['slug'] ?? '', ENT_QUOTES, 'UTF-8') ?></code>
        ｜ <i class="bi bi-bookmark"></i> <?= htmlspecialchars($challenge['category'] ?? '', ENT_QUOTES, 'UTF-8') ?>
        ｜ <i class="bi bi-bar-chart"></i> <?= htmlspecialchars($challenge['difficulty'] ?? '', ENT_QUOTES, 'UTF-8') ?>
        ｜ <i class="bi bi-coin"></i> <?= (int)($challenge['points'] ?? 0) ?> 分
        ｜ <i class="bi bi-clock"></i> v<?= (int)($challenge['version'] ?? 0) ?>
        <?php
        $statusColor = match ($challenge['status'] ?? '') {
            'published' => 'var(--drafting-green)',
            'disabled' => 'var(--drafting-red)',
            default => 'var(--drafting-amber)',
        };
        $statusLabel = match ($challenge['status'] ?? '') {
            'published' => '已發布',
            'disabled' => '已下架',
            default => '草稿',
        };
        ?>
        ｜ <span class="ctf-tag" style="color:<?= $statusColor ?>;border-color:<?= $statusColor ?>">
            <?= $statusLabel ?>
        </span>
    </div>

    <?php if ($error): ?>
        <div class="ctf-flash ctf-flash-error">
            <i class="bi bi-exclamation-triangle-fill"></i> <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="ctf-flash ctf-flash-success">
            <i class="bi bi-check-circle-fill"></i> <?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($challenge['description'])): ?>
        <div class="challenge-description"><?= nl2br(preg_replace('/<script\b[^>]*>.*?<\/script>/si', '', $challenge['description'])) ?></div>
    <?php endif; ?>

    <div class="ctf-dash-actions">
        <a href="/teacher/challenges/<?= $id ?>/edit" class="ctf-btn">
            <i class="bi bi-pencil-square"></i> 編輯資料
        </a>
        <form action="/teacher/challenges/<?= $id ?>/upload" method="post" class="ctf-dash-action-form">
            <?= CSRF::field() ?>
            <button type="submit" class="ctf-btn">
                <i class="bi bi-file-zip-fill"></i> 封裝成 ZIP 套件
            </button>
        </form>
        <?php if (($challenge['status'] ?? '') !== 'published'): ?>
            <?php
            $packageCount = count($packages);
            $hasVersion = $packageCount > 0;
            ?>
            <form action="/teacher/challenges/<?= $id ?>/publish" method="post" class="ctf-dash-action-form"
                  onsubmit="return confirm('發布後所有 active 學生都可以看到此題目。確定？');"
                  <?php if (!$hasVersion): ?>disabled<?php endif; ?>>
                <?= CSRF::field() ?>
                <button type="submit" class="ctf-btn ctf-btn-primary" <?php if (!$hasVersion): ?>disabled<?php endif; ?>>
                    <i class="bi bi-rocket-takeoff"></i> 發布
                </button>
            </form>
        <?php endif; ?>
        <?php if (($challenge['status'] ?? '') === 'published'): ?>
            <form action="/teacher/challenges/<?= $id ?>/disable" method="post" class="ctf-dash-action-form"
                  onsubmit="return confirm('下架後學生無法再看到此題目。確定？');">
                <?= CSRF::field() ?>
                <button type="submit" class="ctf-btn ctf-btn-danger">
                    <i class="bi bi-archive"></i> 下架
                </button>
            </form>
        <?php endif; ?>
        <a href="/teacher/challenges" class="ctf-btn ctf-btn-ghost">
            <i class="bi bi-arrow-left"></i> 回到列表
        </a>
    </div>


    <h2 class="ctf-dash-sub">/ 版本歷史（<?= count($packages) ?> 個版本）</h2>
    <?php if (empty($packages)): ?>
        <p class="ctf-muted">尚未上傳任何版本套件。</p>
    <?php else: ?>
        <table class="ctf-table">
            <thead>
                <tr><th>版本</th><th>SHA-256</th><th>大小</th><th>上傳時間</th></tr>
            </thead>
            <tbody>
                <?php foreach ($packages as $pkg): ?>
                    <tr>
                        <td class="ctf-mono">v<?= (int)$pkg['version'] ?></td>
                        <td class="ctf-mono" style="font-size:12px"><?= substr(htmlspecialchars($pkg['sha256'], ENT_QUOTES, 'UTF-8'), 0, 16) ?>...</td>
                        <td class="ctf-mono"><?= number_format((int)$pkg['file_size'] / 1024, 1) ?> KB</td>
                        <td class="ctf-mono"><?= htmlspecialchars($pkg['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <?php if ($manifest !== null): ?>
        <h2 class="ctf-dash-sub">/ 目前 manifest.json</h2>
        <pre class="ctf-mono" style="background:var(--ink);padding:12px;border:1px solid var(--grid);overflow:auto;font-size:13px"><?= htmlspecialchars(json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?></pre>
    <?php endif; ?>

    <?php
    // List files in the challenge directory (web/ and root)
    $baseDir = rtrim((string)Config::get('STORAGE_CHALLENGE_PATH', 'storage/challenges'), '/\\');
    $challengeDir = $baseDir . '/' . $slug;
    $fileList = [];
    if (is_dir($challengeDir)) {
        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($challengeDir, RecursiveDirectoryIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $rel = str_replace($baseDir . '/', '', $file->getPathname());
                    $fileList[] = str_replace('\\', '/', $rel);
                }
            }
            sort($fileList);
        } catch (\Throwable $e) {
            // Directory unreadable — show empty list, don't crash the page.
        }
    }
    ?>
    <?php if (!empty($fileList)): ?>
    <h2 class="ctf-dash-sub">/ 檔案樹結構</h2>
    <ul class="ctf-mono" style="margin-top:8px;">
        <?php foreach ($fileList as $f): ?>
            <li><?= htmlspecialchars($f, ENT_QUOTES, 'UTF-8') ?></li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>

    <h2 class="ctf-dash-sub">/ 可見性（綁定群組）</h2>
    <p class="ctf-muted">
        <?php if (empty($groupBindings)): ?>
            目前為公開題 — 所有 active 學生都可看到。
        <?php else: ?>
            目前綁定 <?= count($groupBindings) ?> 個群組 — 只有這些群組的成員可看到。
        <?php endif; ?>
    </p>
    <?php if (empty($myGroups)): ?>
        <p class="ctf-muted">你還沒有建立任何群組。<a href="/teacher/groups/new">建立群組</a></p>
    <?php else: ?>
        <form action="/teacher/challenges/<?= $id ?>/groups" method="post" class="ctf-form">
            <?= CSRF::field() ?>
            <div class="ctf-field">
                <span class="ctf-field-label">綁定群組（不選 = 公開）</span>
                <?php foreach ($myGroups as $g): ?>
                    <label style="display:block;padding:4px 0">
                        <input type="checkbox" name="group_ids[]" value="<?= (int)$g['id'] ?>"
                               <?= in_array((int)$g['id'], $groupBindings, true) ? 'checked' : '' ?>>
                        <?= htmlspecialchars($g['name'], ENT_QUOTES, 'UTF-8') ?>
                        <span class="ctf-muted">
                            （join_code: <?= htmlspecialchars($g['join_code'], ENT_QUOTES, 'UTF-8') ?>）
                        </span>
                    </label>
                <?php endforeach; ?>
            </div>
            <div class="ctf-form-actions">
                <button type="submit" class="ctf-btn ctf-btn-primary">
                    <i class="bi bi-people-fill"></i> 更新綁定
                </button>
            </div>
        </form>
    <?php endif; ?>

    <h2 class="ctf-dash-sub">/ 危險操作</h2>
    <form action="/teacher/challenges/<?= $id ?>/delete" method="post"
          onsubmit="return confirm('⚠ 刪除題目將一併刪除所有版本、所有群組綁定、所有 solves。\n這個操作無法復原。\n確定要刪除嗎？');">
        <?= CSRF::field() ?>
        <button type="submit" class="ctf-btn ctf-btn-danger">
            <i class="bi bi-trash"></i> 刪除整個題目
        </button>
    </form>
</section>
