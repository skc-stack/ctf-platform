<?php
$content = file_get_contents('/var/www/html/ctf.kghs.kh.edu.tw/views/student/devices/index.php');
echo "File has copy-code-btn: " . (strpos($content, 'copy-code-btn') !== false ? "YES" : "NO") . "\n";

// Find the code-display section
if (preg_match('/<code id="code-display".*?<\/code>/s', $content, $m)) {
    echo "code-display section:\n" . $m[0] . "\n";
}
unlink(__FILE__);