<?php

declare(strict_types=1);

namespace Didit\Exception;

/**
 * No response arrived: DNS failure, refused connection, TLS error or a
 * connection that dropped.
 */
class ApiConnectionException extends DiditException
{
}
