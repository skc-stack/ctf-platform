<?php
declare(strict_types=1);

namespace CTF\Server\Services;

/**
 * Thrown by TaskService::validate() when the request is rejected.
 * Carries a stable machine-readable `code` and the recommended HTTP status
 * so the controller can format the JSON response.
 */
final class TaskValidationException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 400,
    ) {
        parent::__construct($message);
    }
}
