<?php
use CTF\Server\Security\CSRF;

/** @var array $challenge */
/** @var array $old */
/** @var array $files */
/** @var ?string $error */
/** @var ?string $success */
$challenge = $challenge ?? [];
$old = $old ?? $challenge;
$files = $files ?? [];
$error = $error ?? null;
$success = $success ?? null;
$cid = (int)($challenge['id'] ?? 0);
?>
<section class="ctf-dash">
    <h1 class="ctf-dash-title">
        <i class="bi bi-pencil-square"></i> 編輯題目
    </h1>
    <div class="ctf-dash-rule"></div>
    <p class="ctf-dash-lead">修改題目基本資料。題目識別碼（slug）、難度、類別建立後無法修改。</p>

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

    <form action="/teacher/challenges/<?= $cid ?>" method="post" class="ctf-form">
        <?= CSRF::field() ?>

        <div class="ctf-field-row">
            <label class="ctf-field">
                <span class="ctf-field-label">題目標題 *</span>
                <input type="text" name="title" required maxlength="190"
                       value="<?= htmlspecialchars($old['title'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
            </label>

            <label class="ctf-field">
                <span class="ctf-field-label">題目識別碼 (slug)</span>
                <input type="text" value="<?= htmlspecialchars($challenge['slug'] ?? '', ENT_QUOTES, 'UTF-8') ?>" disabled>
                <small class="ctf-muted">建立後無法修改</small>
            </label>
        </div>

        <div class="ctf-field-row">
            <label class="ctf-field">
                <span class="ctf-field-label">類別</span>
                <input type="text" value="<?= htmlspecialchars($challenge['category'] ?? '', ENT_QUOTES, 'UTF-8') ?>" disabled>
                <small class="ctf-muted">建立後無法修改</small>
            </label>

            <label class="ctf-field">
                <span class="ctf-field-label">難度</span>
                <input type="text" value="<?= htmlspecialchars($challenge['difficulty'] ?? '', ENT_QUOTES, 'UTF-8') ?>" disabled>
                <small class="ctf-muted">建立後無法修改</small>
            </label>

            <label class="ctf-field">
                <span class="ctf-field-label">分數</span>
                <input type="number" name="points" min="1" max="9999"
                       value="<?= htmlspecialchars((string)($old['points'] ?? '100'), ENT_QUOTES, 'UTF-8') ?>">
            </label>
        </div>

        <label class="ctf-field">
            <span class="ctf-field-label">題目說明</span>
            <textarea name="description" id="description" rows="10" maxlength="5000"><?= htmlspecialchars($old['description'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
        </label>

        <div class="ctf-form-actions">
            <button type="submit" class="ctf-btn ctf-btn-primary">
                <i class="bi bi-check2"></i> 儲存
            </button>
            <a href="/teacher/challenges/<?= $cid ?>" class="ctf-btn ctf-btn-ghost">
                <i class="bi bi-x-circle"></i> 取消
            </a>
        </div>
    </form>

    <h2 class="ctf-dash-sub">/ 檔案管理（<?= count($files) ?> 個檔案）</h2>

    <form action="/teacher/challenges/<?= $cid ?>/files" method="post" enctype="multipart/form-data" class="ctf-form">
        <?= CSRF::field() ?>
        <label class="ctf-field">
            <span class="ctf-field-label">新增檔案（多檔上傳）</span>
            <input type="file" name="files[]" multiple
                   accept=".php,.html,.htm,.css,.js,.sql,.txt,.py,.sh,.md">
            <small class="ctf-muted">
                檔名包含路徑（如 <code>web/index.php</code>）會自動放到對應子目錄。
                覆蓋現有同名檔案。
            </small>
        </label>
        <div class="ctf-form-actions">
            <button type="submit" class="ctf-btn ctf-btn-primary">
                <i class="bi bi-upload"></i> 上傳檔案
            </button>
        </div>
    </form>

    <?php if (empty($files)): ?>
        <p class="ctf-muted">目前還沒有上傳任何檔案。請透過上方表單上傳，或回到<a href="/teacher/challenges/<?= $cid ?>/upload">新版本封裝</a>頁面操作。</p>
    <?php else: ?>
        <table class="ctf-table">
            <thead>
                <tr>
                    <th>檔案路徑</th>
                    <th>大小</th>
                    <th>修改時間</th>
                    <th>操作</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($files as $f): ?>
                    <tr>
                        <td class="ctf-mono" style="word-break:break-all;font-size:13px"><?= htmlspecialchars($f['path'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="ctf-mono"><?= number_format((int)$f['size'] / 1024, 1) ?> KB</td>
                        <td class="ctf-mono"><?= htmlspecialchars(date('Y-m-d H:i', (int)$f['modified']), ENT_QUOTES, 'UTF-8') ?></td>
                        <td>
                            <form action="/teacher/challenges/<?= $cid ?>/files/delete" method="post" style="display:inline"
                                  onsubmit="return confirm('確定要刪除 <?= htmlspecialchars(addslashes($f['path']), ENT_QUOTES, 'UTF-8') ?> 嗎？此操作無法復原。');">
                                <?= CSRF::field() ?>
                                <input type="hidden" name="path" value="<?= htmlspecialchars($f['path'], ENT_QUOTES, 'UTF-8') ?>">
                                <button type="submit" class="ctf-btn ctf-btn-sm ctf-btn-danger">
                                    <i class="bi bi-trash"></i> 刪除
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <p class="ctf-dash-hint">
        <i class="bi bi-info-circle"></i>
        提示：刪除檔案或新增檔案後，建議到<a href="/teacher/challenges/<?= $cid ?>">詳情頁</a>點「封裝新版本」讓學生端拿到更新後的內容。
    </p>

    <!-- CKEditor 5 -->
    <script src="https://cdn.ckeditor.com/ckeditor5/41.4.2/classic/ckeditor.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        ClassicEditor.create(document.querySelector('#description'), {
            toolbar: ['heading', '|', 'bold', 'italic', 'link', 'bulletedList', 'numberedList', '|', 'outdent', 'indent', '|', 'imageUpload', 'blockQuote', 'insertTable', '|', 'undo', 'redo'],
            image: {
                upload: {
                    types: ['png', 'jpeg', 'gif', 'webp']
                }
            }
        }).then(function(editor) {
            // Override image upload to use our custom endpoint
            editor.plugins.get('FileRepository').createUploadAdapter = function(loader) {
                return {
                    upload: function() {
                        return loader.file.then(function(file) {
                            return new Promise(function(resolve, reject) {
                                var formData = new FormData();
                                formData.append('upload', file);
                                fetch('/api/v1/teacher/upload-image', {
                                    method: 'POST',
                                    body: formData,
                                    credentials: 'same-origin',
                                    headers: {
                                        'X-CSRF': document.querySelector('input[name="_csrf"]') ? document.querySelector('input[name="_csrf"]').value : ''
                                    }
                                })
                                .then(function(response) { return response.json(); })
                                .then(function(data) {
                                    if (data.url) {
                                        resolve({ default: data.url });
                                    } else {
                                        reject(data.error || '上傳失敗');
                                    }
                                })
                                .catch(function(err) { reject(err); });
                            });
                        });
                    }
                };
            };
        }).catch(function(err) {
            console.error('CKEditor init error:', err);
        });
    });
    </script>
</section>
