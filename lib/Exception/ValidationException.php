<?php
declare(strict_types=1);

namespace OCA\MoodleTalkBridge\Exception;

/**
 * Thrown when a well-signed webhook payload is unprocessable (→ HTTP 422).
 */
class ValidationException extends \RuntimeException {
}
