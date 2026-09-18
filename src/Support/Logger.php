<?php
declare(strict_types=1);

namespace CTF\Server\Support;

use Monolog\Logger as Monolog;
use Monolog\Handler\StreamHandler;
use Monolog\Level;

/**
 * Monolog wrapper. Single channel per request.
 */
final class Logger
{
    private static ?Monolog $logger = null;

    public static function init(string $logPath, string $env): Monolog
    {
        if (self::$logger !== null) {
            return self::$logger;
        }

        $level = $env === 'production' ? Level::Info : Level::Debug;

        $logger = new Monolog('ctf-server');
        $logger->pushHandler(new StreamHandler($logPath, $level));

        self::$logger = $logger;
        return $logger;
    }

    public static function get(): Monolog
    {
        if (self::$logger === null) {
            throw new \RuntimeException('Logger not initialized. Call Logger::init() first.');
        }
        return self::$logger;
    }
}
