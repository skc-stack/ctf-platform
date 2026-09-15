<?php
/**
 * Reflective XSS Demo — web/index.php
 *
 * 漏洞：在搜尋結果的 `<strong>` 標籤中直接 echo $_GET['q']，
 *       沒有做任何編碼，所以 query string 裡的 HTML / JavaScript 會被瀏覽器執行。
 *
 * Flag 藏在 `<meta name="ctf-flag">` 標籤裡 — 學生必須用 XSS 把它讀出來。
 *
 * 學生解法範例（直接貼到網址列）：
 *   /?q="><script>alert(document.querySelector('[name=ctf-flag]').content)</script>
 *
 * 修補方式：把下面 echo 的 `<?= $q ?>` 改成 `<?= htmlspecialchars($q, ENT_QUOTES, 'UTF-8') ?>`。
 */
$q = $_GET['q'] ?? '';
?><!doctype html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <title>Search — Reflective XSS Demo</title>
    <meta name="ctf-flag" content="flag{xss_demo_reflective_v1}">
    <meta name="ctf-hint" content="這是反射型 XSS。找一個地方注入 script。">
    <style>
        body { font-family: 'JetBrains Mono', ui-monospace, Menlo, Consolas, monospace;
               max-width: 720px; margin: 48px auto; padding: 24px;
               color: #c8d3df; background: #0e1116; line-height: 1.6; }
        h1 { color: #bf6f3a; margin: 0 0 16px; }
        form { margin: 16px 0; }
        input[type=text] {
            padding: 8px 12px; background: #0e1116; color: #c8d3df;
            border: 1px solid #2a3138; font-family: inherit; font-size: 14px;
            width: 320px;
        }
        button {
            padding: 8px 16px; background: #bf6f3a; color: #0e1116;
            border: none; font-family: inherit; font-weight: 600; cursor: pointer;
        }
        .result { background: #161b22; padding: 16px; border: 1px solid #2a3138; margin-top: 16px; }
        .hint { background: #161b22; padding: 12px; border-left: 3px solid #bf6f3a; margin-top: 24px; font-size: 14px; }
        .label { color: #8b969e; font-size: 12px; text-transform: uppercase; letter-spacing: 1px; }
    </style>
</head>
<body>
    <h1>Search</h1>
    <p>輸入關鍵字搜尋文件庫。</p>

    <form method="get">
        <input type="text" name="q" value="<?= htmlspecialchars($q, ENT_QUOTES, 'UTF-8') ?>" placeholder="keyword..." autofocus>
        <button type="submit">搜尋</button>
    </form>

    <?php if ($q !== ''): ?>
    <div class="result">
        <div class="label">搜尋結果</div>
        <!-- 漏洞：直接 echo $q，沒編碼 -->
        <p>你搜尋了：<strong><?= $q ?></strong></p>
        <p>找不到任何文件。</p>
    </div>
    <?php endif; ?>

    <div class="hint">
        <strong>提示：</strong>
        試著在搜尋框輸入 <code>&lt;script&gt;...&lt;/script&gt;</code> 看看會發生什麼事。
        頁面 <code>&lt;head&gt;</code> 裡藏著你要找的東西。
    </div>
</body>
</html>
