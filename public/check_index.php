<?php
$file = '/var/www/html/ctf.kghs.kh.edu.tw/views/student/devices/index.php';
$content = file_get_contents($file);
echo "File size: " . strlen($content) . " bytes\n";
echo "Has copy-code-btn: " . (strpos($content, 'copy-code-btn') !== false ? "YES" : "NO") . "\n";

// Show the code-result section
if (preg_match('/<div id="code-result".*?<\/div>\s*<\/div>/s', $content, $m)) {
    echo "\ncode-result section in file:\n";
    echo htmlspecialchars($m[0]);
}
unlink(__FILE__);