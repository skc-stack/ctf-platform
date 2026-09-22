<!doctype html>
<html lang="zh-Hant">
<head>
<meta charset="UTF-8">
<title>測試題目 TEST-001</title>
<style>
body { font-family: sans-serif; background: #0E1A2E; color: #E8E4D6; margin: 0; padding: 40px; }
.ctf-box { max-width: 600px; margin: 0 auto; border: 1px solid #C9A961; padding: 32px; }
h1 { color: #C9A961; font-size: 20px; }
.hint { background: #081424; padding: 16px; border-left: 3px solid #C9A961; margin: 16px 0; font-size: 14px; }
pre { background: #081424; padding: 12px; overflow-x: auto; }
footer { margin-top: 24px; font-size: 12px; color: #6B7787; text-align: center; }
</style>
</head>
<body>
<div class="ctf-box">
  <h1>🔐 測試題目 TEST-001</h1>
  <p>難度：<strong>Easy</strong> | 分值：<strong>100</strong></p>
  <div class="hint">
    <strong>題目描述：</strong><br>
    在網頁原始碼中找找看 flag 藏在哪裡。<br>
    按下 <kbd>Ctrl+U</kbd> 或 <kbd>F12</kbd> 查看原始碼。
  </div>

  <div class="hint" style="background:#0a1f3a;">
    <strong>💡 小提示：</strong><br>
    Flag 藏在 HTML 註解中，請找 <code>&lt;!-- ... --&gt;</code>
  </div>

<?php
$flagFile = __DIR__ . '/.current_flag';
$flag = '';
if (is_file($flagFile)) {
    $flag = trim(file_get_contents($flagFile));
}
?>
<?php if ($flag !== ''): ?>
  <div class="hint" style="border-color: #22c55e; background: #0a2a1a;">
    <strong>✅ 挑戰已啟動 — 你的專屬 Flag：</strong><br>
    <code style="font-size:18px; color:#22c55e;"><?= htmlspecialchars($flag, ENT_QUOTES, 'UTF-8') ?></code>
  </div>
<?php else: ?>
  <div class="hint">
    <strong>⚠️ 尚未啟動挑戰</strong><br>
    請到 <a href="/student" style="color:#C9A961;">CTF Server</a> 開始此題，
    系統會自動將動態 Flag 寫入本题目目錄。
  </div>
<?php endif; ?>

  <footer>
    CTF LAB — 測試題目 TEST-001
  </footer>
</div>
</body>
</html>