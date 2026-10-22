<?php

declare(strict_types=1);

namespace Didit\Exception;

/**
 * HTTP 400 Bad Request: the request is malformed or failed validation.
 */
class BadRequestException extends ApiStatusException
{
}
