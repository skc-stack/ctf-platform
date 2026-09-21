<?php
/** @var string $title */
/** @var string $message */
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title ?? '錯誤', ENT_QUOTES, 'UTF-8') ?> - CTF Target Portal</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: #0e1116;
            color: #c8d3df;
            margin: 0;
            padding: 48px;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .box {
            max-width: 560px;
            padding: 32px;
            border: 1px solid #2a3138;
            background: #161b22;
            border-radius: 8px;
        }
        h1 {
            color: #bf6f3a;
            margin: 0 0 16px;
            font-size: 32px;
        }
        p {
            color: #8b969e;
            margin: 0;
            line-height: 1.6;
        }
        code {
            background: #0e1116;
            color: #f7c884;
            padding: 2px 6px;
            border: 1px solid #2a3138;
            border-radius: 4px;
        }
        .btn {
            display: inline-block;
            margin-top: 20px;
            padding: 10px 20px;
            background: #bf6f3a;
            color: #fff;
            text-decoration: none;
            border-radius: 6px;
            font-size: 14px;
        }
        .btn:hover { background: #a85d2d; }
    </style>
</head>
<body>
    <div class="box">
        <h1><?= htmlspecialchars($title ?? '錯誤', ENT_QUOTES, 'UTF-8') ?></h1>
        <p><?= htmlspecialchars($message ?? '發生未知錯誤', ENT_QUOTES, 'UTF-8') ?></p>
        <a href="/" class="btn">回首頁</a>
    </div>
</body>
</html>
