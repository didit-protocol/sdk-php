<?php

declare(strict_types=1);

namespace Didit\Exception;

/**
 * HTTP 5xx: Didit failed to process the request.
 */
class InternalServerException extends ApiStatusException
{
}
