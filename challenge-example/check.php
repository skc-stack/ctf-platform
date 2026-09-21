<?php
/**
 * check.php - 挑戰任務完成驗證範本
 *
 * 老師需要實作 check() 函式來驗證學生是否完成任務。
 * 完成後可呼叫 getflag() 取得動態 Flag。
 *
 * @package CTF\Challenge
 */

// 引入系統提供的 Flag 函式（請勿修改或刪除此行）
require_once __DIR__ . '/ctf-flag.php';

/**
 * 驗證學生是否完成任務
 *
 * 在此函式中撰寫驗證邏輯，例如：
 * - 檢查特定檔案是否存在
 * - 檢查資料庫中的特定資料
 * - 檢查學生是否完成特定操作
 *
 * @return bool 任務完成返回 true，否則返回 false
 */
function check(): bool
{
    // ==========================================
    // 在下方撰寫你的驗證邏輯
    // ==========================================

    // 範例：檢查是否存在特定檔案
    // $flag_file = __DIR__ . '/hidden/secret.txt';
    // if (!file_exists($flag_file)) {
    //     return false;
    // }

    // 範例：檢查資料庫中的特定值
    // $pdo = new PDO('mysql:host=localhost;dbname=challenge_db', 'ctf_user', 'password');
    // $stmt = $pdo->prepare('SELECT solved FROM tasks WHERE student_id = ?');
    // $stmt->execute([$_SESSION['student_id']]);
    // $result = $stmt->fetch();
    // return $result && $result['solved'] == 1;

    // 範例：檢查請求參數
    // if (!isset($_GET['code']) || $_GET['code'] !== 'secret123') {
    //     return false;
    // }

    // ==========================================
    // 替換為你實際的驗證邏輯
    // ==========================================

    return false; // 預設回傳 false，任務未完成
}

/**
 * 顯示挑戰頁面（範本）
 *
 * 此為 index.php 的範本結構。
 * 老師可以修改 HTML 內容，但建議保留底部的 check() 邏輯。
 */
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>挑戰範本</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            max-width: 800px;
            margin: 50px auto;
            padding: 20px;
            background: #1a1a2e;
            color: #eee;
        }
        .container {
            background: #16213e;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.3);
        }
        h1 { color: #00d9ff; }
        .hint {
            background: rgba(255,193,7,0.1);
            border-left: 4px solid #ffc107;
            padding: 15px;
            margin: 20px 0;
        }
        .btn {
            display: inline-block;
            padding: 12px 24px;
            background: #00d9ff;
            color: #1a1a2e;
            border: none;
            border-radius: 5px;
            font-size: 16px;
            cursor: pointer;
            text-decoration: none;
        }
        .btn:hover { background: #00b8d9; }
        .result {
            margin-top: 20px;
            padding: 15px;
            border-radius: 5px;
            display: none;
        }
        .result.success { background: rgba(40,167,69,0.2); border: 1px solid #28a745; }
        .result.error { background: rgba(220,53,69,0.2); border: 1px solid #dc3545; }
        .flag {
            font-family: monospace;
            font-size: 18px;
            background: #000;
            padding: 10px;
            border-radius: 5px;
            word-break: break-all;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🎯 挑戰標題</h1>
        <p>在這裡撰寫挑戰描述與提示...</p>

        <div class="hint">
            <strong>💡 提示：</strong> 這裡是給學生的提示訊息。
        </div>

        <button class="btn" onclick="submitFlag()">提交解答</button>

        <div id="result" class="result"></div>
    </div>

    <script>
        async function submitFlag() {
            const result = document.getElementById('result');
            result.style.display = 'block';
            result.className = 'result';

            // 嘗試觸發伺服器端驗證
            // 實際的 check() 驗證在伺服器端執行
            try {
                const response = await fetch('/api/check', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        challenge_id: '<?= basename(__DIR__) ?>'
                    })
                });

                const data = await response.json();

                if (data.success && data.flag) {
                    result.className = 'result success';
                    result.innerHTML = `
                        <h3>🎉 恭喜完成！</h3>
                        <p>你的 Flag：</p>
                        <div class="flag">${data.flag}</div>
                    `;
                } else {
                    result.className = 'result error';
                    result.innerHTML = `<h3>❌ 還沒完成</h3><p>${data.message || '請繼續嘗試！'}</p>`;
                }
            } catch (e) {
                result.className = 'result error';
                result.innerHTML = `<h3>❌ 發生錯誤</h3><p>請稍後再試</p>`;
            }
        }
    </script>
</body>
</html>
