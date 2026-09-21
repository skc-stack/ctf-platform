<?php
/**
 * CTF Flag Helper - 由系統提供，請勿修改
 *
 * 此檔案提供 getflag() 函式，用於取得動態 Flag。
 * 檔案路徑和實作細節不應暴露給老師或學生。
 */

// 禁用錯誤顯示以防範資訊洩漏
error_reporting(0);
ini_set('display_errors', 0);

/**
 * 取得動態 Flag
 *
 * 此函式由系統提供，請勿修改。
 * 只有在 check() 回傳 true 時才會呼叫此函式。
 *
 * @return string 動態產生的 Flag
 */
function getflag(): string
{
    // 嘗試從 Portal 取得 flag
    $port = getenv('CTF_PORTAL_PORT') ?: '80';
    $portal_url = "http://127.0.0.1:{$port}/api/flag";

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => json_encode([
                'challenge_id' => basename(__DIR__),
                'student_id' => $_SESSION['student_id'] ?? 'anonymous',
            ]),
            'timeout' => 5,
            'ignore_errors' => true,
        ]
    ]);

    $response = @file_get_contents($portal_url, false, $context);
    if ($response !== false) {
        $data = json_decode($response, true);
        return $data['flag'] ?? 'CTF{FLAG_ERROR}';
    }

    return 'CTF{FLAG_UNAVAILABLE}';
}
