<?php
use CTF\Server\Security\CSRF;

/** @var array $old */
/** @var ?string $error */
$old = $old ?? [];
$error = $error ?? null;
?>
<section class="ctf-dash">
    <h1 class="ctf-dash-title">
        <i class="bi bi-plus-circle"></i> 建立新題目
    </h1>
    <div class="ctf-dash-rule"></div>
    <p class="ctf-dash-lead">填寫題目基本資料，然後上傳 ZIP 套件（系統會自動生成靶機VM所需的資訊檔案 manifest.json）。</p>

    <?php if ($error): ?>
        <div class="ctf-flash ctf-flash-error">
            <i class="bi bi-exclamation-triangle-fill"></i> <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <form action="/teacher/challenges" method="post" enctype="multipart/form-data" class="ctf-form">
        <?= CSRF::field() ?>

        <div class="ctf-field-row">
            <label class="ctf-field">
                <span class="ctf-field-label">題目標題 *</span>
                <input type="text" name="title" required maxlength="190"
                       placeholder="例如：SQL Injection Demo"
                       value="<?= htmlspecialchars($old['title'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
            </label>
        </div>

        <div class="ctf-field-row">
            <label class="ctf-field">
                <span class="ctf-field-label">類別 *</span>
                <select name="category" required>
                    <?php foreach (['web', 'crypto', 'reverse', 'pwn', 'forensic', 'misc', 'network'] as $cat): ?>
                        <option value="<?= $cat ?>" <?= ($old['category'] ?? 'web') === $cat ? 'selected' : '' ?>><?= $cat ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label class="ctf-field">
                <span class="ctf-field-label">難度 *</span>
                <select name="difficulty" required>
                    <?php foreach (['easy', 'medium', 'hard', 'expert'] as $diff): ?>
                        <option value="<?= $diff ?>" <?= ($old['difficulty'] ?? 'easy') === $diff ? 'selected' : '' ?>><?= $diff ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label class="ctf-field">
                <span class="ctf-field-label">分數</span>
                <input type="number" name="points" min="1" max="9999" value="<?= htmlspecialchars((string)($old['points'] ?? '100'), ENT_QUOTES, 'UTF-8') ?>">
            </label>

            <label class="ctf-field">
                <span class="ctf-field-label">解題時限（分鐘）</span>
                <input type="number" name="time_limit_minutes" min="5" max="480" placeholder="留空使用系統預設"
                       value="<?= htmlspecialchars((string)($old['time_limit_minutes'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                <small class="ctf-muted">留空則使用系統預設值（120分鐘）</small>
            </label>
        </div>

        <p class="ctf-muted" style="margin-top:-8px">
            <i class="bi bi-info-circle"></i>
            題目識別碼（slug）會由系統自動產生，格式為
            <code>&lt;難度&gt;-&lt;類別&gt;-&lt;序號&gt;</code>（例如 <code>easy-web-001</code>）。
            <strong>請小心選擇難度和類別，建立後無法修改。</strong>
        </p>

        <label class="ctf-field">
            <span class="ctf-field-label">題目說明</span>
            <textarea name="description" id="description" rows="10" maxlength="5000"
                      placeholder="給學生看的題目描述…"><?= htmlspecialchars($old['description'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
        </label>

        <label class="ctf-field">
            <span class="ctf-field-label">題目檔案（多檔上傳，可選填）</span>
            <input type="file" name="files[]" multiple
                   accept=".php,.html,.htm,.css,.js,.sql,.txt,.py,.sh,.md">
            <input type="hidden" name="PHP_SESSION_UPLOAD_PROGRESS" value="">
            <small class="ctf-muted">
                可一次選多個檔案(支援的副檔名：PHP、HTML、CSS、JS、SQL、純文字、Python、Shell、Markdown)。
            </small>
        </label>

        <p class="ctf-muted" style="margin-top:-8px">
            <i class="bi bi-info-circle"></i>
            送出後，系統會自動建立 <code>storage/challenges/&lt;slug&gt;/</code> 目錄結構（包含 <code>manifest.json</code> ），
            把你的檔案放到對應位置。之後可以在詳情頁繼續上傳新檔案、封裝新版本。
        </p>

        <div class="ctf-form-actions">
            <button type="submit" class="ctf-btn ctf-btn-primary">
                <i class="bi bi-plus-circle"></i> 建立題目
            </button>
            <a href="/teacher/challenges" class="ctf-btn ctf-btn-ghost">
                <i class="bi bi-x-circle"></i> 取消
            </a>
        </div>
    </form>

    <!-- CKEditor 5 -->
    <script src="https://cdn.ckeditor.com/ckeditor5/41.4.2/classic/ckeditor.js"></script>
    <style>
    /* Set default text color to black */
    .ck-editor .ck-content { color: #000000 !important; }
    .ck-editor .ck-content p, .ck-editor .ck-content li, .ck-editor .ck-content span { color: #000000 !important; }
    </style>
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
            // Set initial content to black
            editor.model.document.forEachChange(function(operation, batch) {});
            // Apply black color to new content
            editor.model.on('insertContent', function(evt, content) {
                // Default text to black will be handled by CSS
            });
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
