<?php

declare(strict_types=1);

namespace Didit\Exception;

/**
 * HTTP 409 Conflict: the request conflicts with the current state of the resource.
 */
class ConflictException extends ApiStatusException
{
}
