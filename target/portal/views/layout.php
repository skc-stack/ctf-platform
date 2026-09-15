<?php
/**
 * @var string $title
 * @var string $bodyHtml
 */
$title = $title ?? 'CTF Target Portal';
$bodyHtml = $bodyHtml ?? '';
?>
<!doctype html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;700&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --ink: #0e1116;
            --paper: #c8d3df;
            --paper-mute: #8b969e;
            --grid: #2a3138;
            --brass: #bf6f3a;
            --drafting-amber: #f7c884;
            --drafting-red: #f25555;
            --drafting-green: #6abe6a;
            --font-mono: 'JetBrains Mono', ui-monospace, Menlo, Consolas, monospace;
        }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; }
        body {
            background: var(--ink);
            color: var(--paper);
            font-family: var(--font-mono);
            min-height: 100vh;
            padding: 32px 16px;
            line-height: 1.55;
        }
        .shell {
            max-width: 880px;
            margin: 0 auto;
        }
        header.bar {
            border: 1px solid var(--grid);
            background: #161b22;
            padding: 16px 20px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        header.bar .brand {
            font-weight: 700;
            color: var(--brass);
            letter-spacing: 2px;
        }
        nav.bar a {
            color: var(--paper);
            text-decoration: none;
            margin-left: 16px;
            border-bottom: 1px solid transparent;
            padding-bottom: 2px;
        }
        nav.bar a:hover {
            color: var(--brass);
            border-bottom-color: var(--brass);
        }
        h1 { color: var(--brass); margin: 0 0 8px; font-size: 24px; letter-spacing: 1px; }
        h2 { color: var(--paper); margin: 24px 0 12px; font-size: 16px; letter-spacing: 1px; }
        .lead { color: var(--paper-mute); margin: 0 0 24px; }
        .card {
            border: 1px solid var(--grid);
            background: #161b22;
            padding: 24px;
            margin-bottom: 16px;
        }
        form.cform { display: flex; flex-direction: column; gap: 16px; }
        form.cform label { display: flex; flex-direction: column; gap: 6px; }
        form.cform .lbl { color: var(--paper-mute); font-size: 14px; }
        form.cform input, form.cform textarea {
            background: var(--ink);
            color: var(--paper);
            border: 1px solid var(--grid);
            padding: 10px 12px;
            font-family: var(--font-mono);
            font-size: 15px;
        }
        form.cform input:focus, form.cform textarea:focus {
            outline: none;
            border-color: var(--brass);
        }
        .actions { display: flex; gap: 12px; flex-wrap: wrap; margin-top: 8px; }
        .btn {
            background: var(--paper);
            color: var(--ink);
            border: 1px solid var(--paper);
            padding: 8px 16px;
            font-family: var(--font-mono);
            font-size: 14px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }
        .btn:hover { background: var(--brass); color: var(--ink); border-color: var(--brass); }
        .btn.primary { background: var(--brass); border-color: var(--brass); color: var(--ink); }
        .btn.ghost { background: transparent; color: var(--paper); border-color: var(--grid); }
        .btn.danger { background: var(--drafting-red); border-color: var(--drafting-red); color: var(--ink); }
        .alert {
            padding: 12px 16px;
            border: 1px solid var(--drafting-red);
            color: var(--drafting-red);
            margin-bottom: 16px;
        }
        .alert.ok { border-color: var(--drafting-green); color: var(--drafting-green); }
        .stat-row { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 16px; }
        .stat {
            flex: 1 1 200px;
            border: 1px solid var(--grid);
            background: #161b22;
            padding: 16px;
        }
        .stat .k { color: var(--paper-mute); font-size: 13px; text-transform: uppercase; letter-spacing: 1px; }
        .stat .v { font-size: 20px; margin-top: 6px; color: var(--drafting-amber); word-break: break-all; }
        table.t { width: 100%; border-collapse: collapse; }
        table.t th, table.t td { padding: 10px 12px; text-align: left; border-bottom: 1px solid var(--grid); font-size: 14px; }
        table.t th { color: var(--paper-mute); font-weight: 500; text-transform: uppercase; letter-spacing: 1px; font-size: 12px; }
        code { background: var(--ink); padding: 2px 6px; border: 1px solid var(--grid); color: var(--drafting-amber); }
        .hint { color: var(--paper-mute); font-size: 13px; margin-top: 16px; }
        .copy-btn {
            background: transparent;
            border: 1px solid var(--grid);
            color: var(--paper);
            padding: 4px 10px;
            cursor: pointer;
            font-family: var(--font-mono);
            font-size: 13px;
            margin-left: 8px;
        }
        .copy-btn:hover { border-color: var(--brass); color: var(--brass); }
    </style>
</head>
<body>
<div class="shell">
    <header class="bar">
        <span class="brand">CTF TARGET PORTAL</span>
        <nav class="bar">
            <a href="/">首頁</a>
            <a href="/activate">啟用</a>
            <a href="/task">挑戰</a>
        </nav>
    </header>
    <?= $bodyHtml ?>
</div>
<script>
function copyText(text, btn) {
    var orig = btn.dataset.orig || btn.innerHTML;
    if (!btn.dataset.orig) btn.dataset.orig = orig;
    var done = function () {
        btn.innerHTML = '<i>✓</i> 已複製';
        btn.disabled = true;
        setTimeout(function () { btn.innerHTML = orig; btn.disabled = false; }, 1500);
    };
    var fallback = function () {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        try { document.execCommand('copy'); done(); }
        catch (e) { alert('複製失敗：\n' + text); }
        document.body.removeChild(ta);
    };
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text).then(done, fallback);
    } else {
        fallback();
    }
}
</script>
</body>
</html>
