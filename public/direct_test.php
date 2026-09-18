<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require '/var/www/html/ctf.kghs.kh.edu.tw/vendor/autoload.php';
require '/var/www/html/ctf.kghs.kh.edu.tw/bootstrap.php';

$_SESSION['user'] = ['id' => 2, 'username' => 'CtfAdmin', 'role' => 'admin', 'status' => 'active'];

try {
    $content = \CTF\Server\Http\View::render('admin/dashboard', ['stats' => [], 'recent_audit' => []]);

    if (strpos($content, '裝置管理') !== false) {
        echo "SUCCESS: 裝置管理 FOUND\n";
    } else {
        echo "FAIL: 裝置管理 NOT FOUND\n";
        if (preg_match('/ctf-dash-actions.*?<\/div>/s', $content, $m)) {
            echo "Content: \n" . $m[0] . "\n";
        }
    }
} catch (Throwable $e) {
    echo "Exception: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . "\n";
    echo "Line: " . $e->getLine() . "\n";
}
unlink(__FILE__);