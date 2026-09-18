<?php
echo "<!-- base.php version: ";
$content = file_get_contents('/var/www/html/ctf.kghs.kh.edu.tw/views/layouts/base.php');
if (preg_match('/v\d+/', $content, $m)) {
    echo $m[0];
} else {
    echo "no version found";
}
echo " -->\n";
echo "<!-- md5: " . md5_file('/var/www/html/ctf.kghs.kh.edu.tw/views/layouts/base.php') . " -->\n";
echo "<!-- mtime: " . date('Y-m-d H:i:s', filemtime('/var/www/html/ctf.kghs.kh.edu.tw/views/layouts/base.php')) . " -->\n";
unlink(__FILE__);