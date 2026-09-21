<?php
/**
 * index.php - 挑戰進入點範本
 *
 * 這是學生造訪挑戰時的第一個頁面。
 * 建議：只需要修改 HTML 內容，底部的驗證邏輯保持不變。
 */

session_start();

// 取得挑戰目錄名稱（slug）
$challengeSlug = basename(__DIR__);

// 如果需要，可以從資料庫讀取挑戰資料
// $challengeData = file_get_contents(__DIR__ . '/manifest.json');
// $manifest = json_decode($challengeData, true);
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SQL Injection 挑戰</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: #0f0f1a;
            color: #e0e0e0;
            margin: 0;
            padding: 20px;
            min-height: 100vh;
        }
        .container {
            max-width: 900px;
            margin: 0 auto;
            background: #1a1a2e;
            border-radius: 12px;
            padding: 30px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.4);
        }
        h1 {
            color: #00ff88;
            border-bottom: 2px solid #00ff88;
            padding-bottom: 10px;
        }
        .description {
            background: #16213e;
            padding: 20px;
            border-radius: 8px;
            line-height: 1.8;
        }
        .description code {
            background: #0f0f1a;
            padding: 2px 8px;
            border-radius: 4px;
            color: #ff6b6b;
        }
        pre {
            background: #0f0f1a;
            padding: 15px;
            border-radius: 8px;
            overflow-x: auto;
            border-left: 4px solid #00ff88;
        }
        .input-group {
            margin: 20px 0;
        }
        label {
            display: block;
            margin-bottom: 8px;
            color: #aaa;
        }
        input[type="text"] {
            width: 100%;
            max-width: 400px;
            padding: 12px;
            border: 2px solid #333;
            border-radius: 6px;
            background: #0f0f1a;
            color: #fff;
            font-size: 16px;
        }
        input[type="text"]:focus {
            outline: none;
            border-color: #00ff88;
        }
        button {
            background: #00ff88;
            color: #0f0f1a;
            border: none;
            padding: 12px 30px;
            border-radius: 6px;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.3s;
        }
        button:hover {
            background: #00cc6a;
            transform: translateY(-2px);
        }
        .result {
            margin-top: 20px;
            padding: 20px;
            border-radius: 8px;
            display: none;
        }
        .result.success {
            display: block;
            background: rgba(0, 255, 136, 0.1);
            border: 2px solid #00ff88;
        }
        .result.error {
            display: block;
            background: rgba(255, 107, 107, 0.1);
            border: 2px solid #ff6b6b;
        }
        .flag-box {
            background: #000;
            padding: 15px;
            border-radius: 8px;
            margin-top: 15px;
            font-family: monospace;
            font-size: 18px;
            color: #00ff88;
            word-break: break-all;
        }
        .hint {
            background: rgba(255, 193, 7, 0.1);
            border-left: 4px solid #ffc107;
            padding: 15px;
            margin: 20px 0;
            border-radius: 0 8px 8px 0;
        }
        .hint strong { color: #ffc107; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔐 SQL Injection 挑戰</h1>

        <div class="description">
            <p>歡迎來到 SQL Injection 挑戰！你的任务是<strong>找到隱藏在資料庫中的 Flag</strong>。</p>
            <p>提示：小心那些不安全的 SQL 查詢...</p>
        </div>

        <div class="hint">
            <strong>💡 提示：</strong>
            嘗試在輸入框中輸入一些特殊字元，看看會發生什麼。
            正確的 SQL 注入可以讓你繞過登入驗證。
        </div>

        <form method="POST" action="check.php">
            <div class="input-group">
                <label for="username">使用者名稱：</label>
                <input type="text" id="username" name="username" placeholder="輸入使用者名稱" autocomplete="off">
            </div>
            <div class="input-group">
                <label for="password">密碼：</label>
                <input type="text" id="password" name="password" placeholder="輸入密碼" autocomplete="off">
            </div>
            <button type="submit">登入</button>
        </form>

        <div id="result"></div>
    </div>
</body>
</html>
