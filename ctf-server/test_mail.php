<?php
/**
 * Test Nylas email sending from command line.
 * Usage: php test_mail.php
 */

require __DIR__ . '/../bootstrap.php';

use CTF\Server\Services\NylasClient;
use CTF\Server\Support\Config;

$nylas = new NylasClient();

echo "=== Nylas Configuration ===\n";
echo "API URI:    " . Config::get('NYLAS_API_URI', '(not set)') . "\n";
echo "API Key:    " . (strlen(Config::get('NYLAS_API_KEY', '')) > 8 ? substr(Config::get('NYLAS_API_KEY', ''), 0, 8) . '...' : '(not set)') . "\n";
echo "Grant ID:   " . Config::get('NYLAS_GRANT_ID', '(not set)') . "\n";
echo "From Name:  " . Config::get('NYLAS_FROM_NAME', '(not set)') . "\n";
echo "From Email: " . Config::get('NYLAS_FROM_EMAIL', '(not set)') . "\n\n";

echo "Configured: " . ($nylas->isConfigured() ? 'YES' : 'NO') . "\n\n";

// Test send
$toEmail = 'skc@ms2.kghs.kh.edu.tw';
$subject = '[CTF LAB] Test Email';
$body = '<p>This is a test email from CTF LAB password reset system.</p>';
$toName = 'Test User';

echo "Sending test email to: $toEmail\n";

try {
    $result = $nylas->send($toEmail, $subject, $body, $toName);
    echo "\n✓ SUCCESS! Message ID: " . ($result['data']['id'] ?? 'unknown') . "\n";
    echo "Response: " . json_encode($result, JSON_PRETTY_PRINT) . "\n";
} catch (Exception $e) {
    echo "\n✗ FAILED: " . $e->getMessage() . "\n";
    exit(1);
}
