<?php

declare(strict_types=1);

namespace Didit\Exception;

/**
 * No response arrived: DNS failure, refused connection, TLS error or a
 * connection that dropped.
 */
class ApiConnectionException extends DiditException
{
    public function __construct(
        string $message,
        /**
         * False only when the connection failed before the request was sent
         * (DNS, connect or TLS failure), so retrying cannot repeat it.
         */
        public readonly bool $mayHaveReachedServer = true,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
