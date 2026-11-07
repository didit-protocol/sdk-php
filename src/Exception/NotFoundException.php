<?php

declare(strict_types=1);

namespace Didit\Exception;

/**
 * HTTP 404 Not Found: the resource does not exist or is outside the key's scope.
 */
class NotFoundException extends ApiStatusException
{
}
