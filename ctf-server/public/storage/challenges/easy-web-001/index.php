<?php
/**
 * DEMO-001 — Hidden in HTML Comments
 *
 * The flag is in an HTML comment below. Read the source (Ctrl+U / View Source)
 * to find it. In real challenges, this kind of leak is rare — most flags
 * require actual exploitation (SQLi, XSS, SSRF, etc.).
 *
 * Note for student: this page uses a *static* flag because verification_type
 * is "flag" with flag_static in manifest.json. The Server still wraps this
 * in an HMAC when computing the expected flag, so just pasting the static
 * value won't match — the student's flag is computed from
 * (student_id, challenge_uuid, task_uuid) and the FLAG_MASTER_SECRET on
 * the Server.
 *
 * So: this page is a *red herring*. The real challenge flow is:
 *   1. Student starts a task for DEMO-001 in their dashboard.
 *   2. Server gives them a task UUID.
 *   3. Server computes expected flag = HMAC(student + challenge + task, MASTER).
 *   4. Student pastes that expected flag back into the dashboard's submit form.
 *
 * This page is purely so the student has something visible at the entrypoint.
 */
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
    </style>
</head>
<body>
    <h1>DEMO-001</h1>
    <p>歡迎。這是 CTF LAB 的入門題。Flag 就藏在這個頁面裡的某個地方。</p>

    <!--
        Hint to the reader: this challenge is intentionally trivial — it teaches
        you to read HTML source. The "flag" text you find here is a static comment,
        not the actual flag the Server will accept. The real flag is computed by
        the Server when you start a task, based on (student_id, challenge_uuid,
        task_uuid) and a master secret. You'll need to submit that computed flag
        from your dashboard.

        For this demo, the static value in manifest.json's flag_static is
        "flag{read_the_source}" — but only the HMAC'd version counts.
    -->

    <div class="hint">
        <strong>提示：</strong> 按 <code>Ctrl+U</code>（或瀏覽器 → 檢視網頁原始碼）可以看到這個頁面的 HTML 內容。
        Flag 通常藏在「HTML 註解」裡或「JS 變數」裡或「看不見的 element」裡。
    </div>

    <h2>你的下一步</h2>
    <ol>
        <li>回到 CTF LAB 學生儀表板</li>
        <li>啟動 DEMO-001 的 Task</li>
        <li>把 Server 給你的 Task Token 貼到 Target Portal</li>
        <li>解完題後回 Server 學生儀表板「繳交 Flag」</li>
    </ol>
</body>
</html>
