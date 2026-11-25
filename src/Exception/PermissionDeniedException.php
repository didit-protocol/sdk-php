<?php

declare(strict_types=1);

namespace Didit\Exception;

/**
 * HTTP 403 Forbidden: the credentials have no access to this resource or action.
 */
class PermissionDeniedException extends ApiStatusException
{
}
