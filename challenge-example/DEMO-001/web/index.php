<?php
/**
 * DEMO-001 — Hidden in HTML Comments
 *
 * Dynamic Flag Mode:
 * The Agent writes the current task's dynamic flag to /srv/ctf/challenges/DEMO-001/.current_flag
 * This page reads and displays that flag.
 */
$challenge_root = '/srv/ctf/challenges';
$challenge_id = 'DEMO-001';
$flag_file = $challenge_root . '/' . $challenge_id . '/.current_flag';

$dynamic_flag = '';
if (file_exists($flag_file)) {
    $dynamic_flag = trim(file_get_contents($flag_file));
}
?>
<!doctype html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <title>DEMO-001 — Hidden in HTML Comments</title>
    <style>
        body { font-family: 'JetBrains Mono', monospace; max-width: 720px; margin: 48px auto; padding: 24px; color: #c8d3df; background: #0e1116; }
        h1 { color: #bf6f3a; }
        code { background: #161b22; padding: 2px 6px; border: 1px solid #2a3138; color: #f7c884; }
        .hint { background: #161b22; padding: 12px; border-left: 3px solid #bf6f3a; }
        .flag-box { background: #161b22; padding: 16px; border: 2px solid #bf6f3a; margin: 20px 0; text-align: center; }
        .flag-box .flag { font-size: 24px; color: #f7c884; letter-spacing: 2px; }
        .flag-box .label { color: #888; margin-bottom: 8px; }
    </style>
</head>
<body>
    <h1>DEMO-001</h1>
    <p>歡迎。這是 CTF LAB 的入門題。</p>

    <?php if ($dynamic_flag): ?>
    <div class="flag-box">
        <div class="label">你的 Flag（動態計算）</div>
        <div class="flag"><?= htmlspecialchars($dynamic_flag, ENT_QUOTES, 'UTF-8') ?></div>
    </div>
    <?php else: ?>
    <div class="hint">
        <strong>提示：</strong> 這個頁面需要由 Agent 提供動態 Flag。
        請先在 Target Portal 貼上 Task Token 來啟動挑戰。
    </div>
    <?php endif; ?>

    <h2>如何解題</h2>
    <ol>
        <li>複製上面的 Flag</li>
        <li>回到 CTF LAB 學生儀表板</li>
        <li>在「繳交 Flag」表單貼上並送出</li>
    </ol>

    <h2>關於這個挑戰</h2>
    <p>這是一個入門題。Flag 就在這個頁面中。試著找到它！</p>
</body>
</html>
