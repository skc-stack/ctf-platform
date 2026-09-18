<?php
$content = file_get_contents('/var/www/html/ctf.kghs.kh.edu.tw/views/student/devices/index.php');
echo "File length: " . strlen($content) . " bytes\n";
echo "Has copy-code-btn: " . (strpos($content, 'copy-code-btn') !== false ? "YES" : "NO") . "\n";
echo "Has 複製: " . (strpos($content, '複製') !== false ? "YES" : "NO") . "\n";

// Show lines around line 25
$lines = explode("\n", $content);
echo "\nLines 20-30:\n";
for ($i = 19; $i < 30 && $i < count($lines); $i++) {
    echo "Line " . ($i+1) . ": " . $lines[$i] . "\n";
}
unlink(__FILE__);