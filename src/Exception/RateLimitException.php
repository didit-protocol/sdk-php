<?php

declare(strict_types=1);

namespace Didit\Exception;

/**
 * HTTP 429 Too Many Requests: slow down; the SDK already retried as configured.
 */
class RateLimitException extends ApiStatusException
{
}
