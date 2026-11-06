<?php

declare(strict_types=1);

namespace Didit\Tests\Support;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\RequestInterface;

/**
 * Builds Guzzle transport exceptions the way the installed Guzzle major would raise them.
 *
 * Guzzle 7 passes the cURL details as a handler context argument. Guzzle 8 dropped that
 * argument and the exceptions' getHandlerContext(), so the details live only in the message.
 * The classes are instantiated by name so one test suite type-checks against both majors.
 */
final class GuzzleErrors
{
    /**
     * A connection-phase failure ({@see ConnectException}), as cURL reports it.
     */
    public static function connect(string $message, RequestInterface $request, int $errno, string $error): \Throwable
    {
        return self::build(ConnectException::class, self::isGuzzle8()
            ? [$message, $request]
            : [$message, $request, null, ['errno' => $errno, 'error' => $error]]);
    }

    /**
     * A non-connection transport failure ({@see RequestException}), as cURL reports it.
     */
    public static function request(string $message, RequestInterface $request, int $errno, string $error): \Throwable
    {
        return self::build(RequestException::class, self::isGuzzle8()
            ? [$message, $request]
            : [$message, $request, null, null, ['errno' => $errno, 'error' => $error]]);
    }

    public static function isGuzzle8(): bool
    {
        return class_exists(\GuzzleHttp\Exception\NetworkException::class);
    }

    /**
     * @param class-string<\Throwable> $class
     * @param list<mixed> $arguments
     */
    private static function build(string $class, array $arguments): \Throwable
    {
        // Reflection keeps static analysis from checking the arguments against a single Guzzle major.
        return (new \ReflectionClass($class))->newInstanceArgs($arguments);
    }
}
