<?php

declare(strict_types=1);

namespace Didit\Exception;

/**
 * HTTP 401 Unauthorized: the API key or token is missing, wrong, revoked or expired.
 */
class AuthenticationException extends ApiStatusException
{
}
