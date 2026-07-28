<?php
declare(strict_types=1);

namespace OCA\MoodleTalkBridge\Exception;

/** Raised when an SSO ticket fails signature or TTL verification (D12). */
class InvalidTicketException extends \RuntimeException {
}
