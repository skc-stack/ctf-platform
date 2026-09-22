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
    // 挑戰目錄名稱當作 challenge_slug
    $challengeSlug = basename(__DIR__);

    // 從 URL query parameter 取得 task_id
    // Portal 會在 redirect 時傳入 ?task_id=XXX
    $taskId = isset($_GET['task_id']) ? (int)$_GET['task_id'] : 0;

    if ($taskId === 0) {
        return 'CTF{FLAG_NO_TASK_ID}';
    }

    // 透過 Portal -> Agent -> Server 取得 flag
    $port = getenv('CTF_PORTAL_PORT') ?: '80';
    $portalUrl = "http://127.0.0.1:{$port}/getflag";

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => json_encode([
                'task_id' => $taskId,
                'challenge_slug' => $challengeSlug,
            ]),
            'timeout' => 10,
            'ignore_errors' => true,
        ]
    ]);

    $response = @file_get_contents($portalUrl, false, $context);
    if ($response !== false) {
        $data = json_decode($response, true);
        if (isset($data['flag']) && $data['flag'] !== '') {
            return $data['flag'];
        }
        if (isset($data['error'])) {
            return 'CTF{FLAG_ERROR: ' . $data['error'] . '}';
        }
    }

    return 'CTF{FLAG_UNAVAILABLE}';
}
