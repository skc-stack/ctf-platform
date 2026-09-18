<?php
declare(strict_types=1);

use CTF\Server\Support\Config;
use CTF\Server\Support\Logger;
use Dotenv\Dotenv;

/**
 * Bootstrap: load .env, init config, autoload, session, error handler, DB.
 * Returns configured PDO Connection (lazy) ready for use.
 */
(function () {
    $base = __DIR__;

    // Capture script boot time for the layout's render-duration indicator.
    if (!defined('CTF_BOOT_TS')) {
        define('CTF_BOOT_TS', microtime(true));
    }

    // 1. Composer autoload
    $autoload = $base . '/vendor/autoload.php';
    if (!is_file($autoload)) {
        fwrite(STDERR, "[bootstrap] vendor/autoload.php missing — run composer install\n");
        http_response_code(500);
        echo 'Server not installed (composer install required).';
        exit;
    }
    require $autoload;

    // 2. .env (Dotenv v5: createImmutable + load, does not override existing $_ENV/$_SERVER)
    $envFile = $base . '/.env';
    if (!is_file($envFile)) {
        fwrite(STDERR, "[bootstrap] .env missing — copy from .env.example\n");
        http_response_code(500);
        echo '.env file missing.';
        exit;
    }
    $dotenv = Dotenv::createImmutable($base);
    $dotenv->safeLoad(); // do not throw if optional vars missing

    // 3. Config
    Config::load([
        'APP_NAME' => $_ENV['APP_NAME'] ?? 'CTF LAB',
        'APP_ENV' => $_ENV['APP_ENV'] ?? 'production',
        'APP_DEBUG' => $_ENV['APP_DEBUG'] ?? 'false',
        'APP_URL' => $_ENV['APP_URL'] ?? '',
        'APP_TIMEZONE' => $_ENV['APP_TIMEZONE'] ?? 'UTC',
        'APP_VERSION' => $_ENV['APP_VERSION'] ?? '0.4',
        'APP_KEY' => $_ENV['APP_KEY'] ?? null,

        'NYLAS_API_URI' => $_ENV['NYLAS_API_URI'] ?? '',
        'NYLAS_API_KEY' => $_ENV['NYLAS_API_KEY'] ?? '',
        'NYLAS_GRANT_ID' => $_ENV['NYLAS_GRANT_ID'] ?? '',
        'NYLAS_FROM_NAME' => $_ENV['NYLAS_FROM_NAME'] ?? 'CTF LAB',
        'NYLAS_FROM_EMAIL' => $_ENV['NYLAS_FROM_EMAIL'] ?? '',

        'DB_HOST' => $_ENV['DB_HOST'] ?? '127.0.0.1',
        'DB_PORT' => $_ENV['DB_PORT'] ?? '3306',
        'DB_DATABASE' => $_ENV['DB_DATABASE'] ?? '',
        'DB_USERNAME' => $_ENV['DB_USERNAME'] ?? '',
        'DB_PASSWORD' => $_ENV['DB_PASSWORD'] ?? '',

        'SESSION_NAME' => $_ENV['SESSION_NAME'] ?? 'ctf_session',
        'SESSION_LIFETIME' => $_ENV['SESSION_LIFETIME'] ?? '7200',

        'FLAG_MASTER_SECRET' => $_ENV['FLAG_MASTER_SECRET'] ?? null,

        'STORAGE_CHALLENGE_PATH' => $_ENV['STORAGE_CHALLENGE_PATH'] ?? 'storage/challenges',
        'STORAGE_LOG_PATH' => $_ENV['STORAGE_LOG_PATH'] ?? 'storage/logs',
        'STORAGE_CHALLENGE_FALLBACK' => $_ENV['STORAGE_CHALLENGE_FALLBACK'] ?? 'storage/challenges',
        'STORAGE_LOG_FALLBACK' => $_ENV['STORAGE_LOG_FALLBACK'] ?? 'storage/logs',

        'RATE_LIMIT_LOGIN' => $_ENV['RATE_LIMIT_LOGIN'] ?? '5',
        'RATE_LIMIT_ACTIVATION' => $_ENV['RATE_LIMIT_ACTIVATION'] ?? '5',
        'RATE_LIMIT_PASSWORD_RESET' => $_ENV['RATE_LIMIT_PASSWORD_RESET'] ?? '3',
        'RATE_LIMIT_TASK_VALIDATE' => $_ENV['RATE_LIMIT_TASK_VALIDATE'] ?? '10',
        'RATE_LIMIT_FLAG_SUBMIT' => $_ENV['RATE_LIMIT_FLAG_SUBMIT'] ?? '10',

        'EMAIL_VERIFICATION_TTL' => $_ENV['EMAIL_VERIFICATION_TTL'] ?? '1440',
        'PASSWORD_RESET_TTL' => $_ENV['PASSWORD_RESET_TTL'] ?? '60',

        'DEFAULT_TASK_TTL' => $_ENV['DEFAULT_TASK_TTL'] ?? '120',
        'MAX_DEVICES_PER_STUDENT' => $_ENV['MAX_DEVICES_PER_STUDENT'] ?? '3',
    ]);

    // 4. Timezone
    date_default_timezone_set(Config::get('APP_TIMEZONE', 'UTC'));

    // 5. Logger
    $logPath = Config::get('STORAGE_LOG_PATH') ?: Config::get('STORAGE_LOG_FALLBACK', 'storage/logs');
    if (!str_starts_with($logPath, '/') && !preg_match('#^[A-Z]:[\\\\/]#i', $logPath)) {
        $logPath = $base . '/' . $logPath;
    }
    // If path resolves to a directory (or doesn't end in .log), append filename.
    if (is_dir($logPath) || !str_ends_with($logPath, '.log')) {
        $logPath = rtrim($logPath, '/\\') . '/ctf-server.log';
    }
    $logDir = dirname($logPath);
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0775, true);
    }
    Logger::init($logPath, Config::get('APP_ENV', 'production'));

    // 6. Error handler
    $debug = Config::bool('APP_DEBUG', false);
    set_error_handler(function ($severity, $message, $file, $line) use ($debug) {
        if (!(error_reporting() & $severity)) {
            return;
        }
        Logger::get()->error('PHP error', [
            'severity' => $severity,
            'message' => $message,
            'file' => $file,
            'line' => $line,
        ]);
        if ($debug) {
            throw new \ErrorException($message, 0, $severity, $file, $line);
        }
    });

    set_exception_handler(function (\Throwable $e) use ($debug) {
        Logger::get()->critical('Unhandled exception', [
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
        ]);
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
        }
        if ($debug) {
            echo "Fatal: " . $e->getMessage() . "\n\n" . $e->getTraceAsString();
        } else {
            echo '500 Internal Server Error';
        }
    });

    // 7. Session
    $sessionName = Config::get('SESSION_NAME', 'ctf_session');
    session_name($sessionName);
    $secure = Config::bool('APP_DEBUG', false) === false; // secure cookie when not local debug
    session_set_cookie_params([
        'lifetime' => (int)Config::get('SESSION_LIFETIME', 7200),
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
})();
