<?php

declare(strict_types=1);

namespace Didit\Exception;

/**
 * HTTP 422 Unprocessable Entity: the request is well formed but cannot be processed.
 */
class UnprocessableEntityException extends ApiStatusException
{
}
