<?php

declare(strict_types=1);

namespace Didit\Exception;

/**
 * The request did not complete within the configured timeout.
 */
class ApiTimeoutException extends ApiConnectionException
{
}
