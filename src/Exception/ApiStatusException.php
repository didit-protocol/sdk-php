<?php

declare(strict_types=1);

namespace Didit\Exception;

use Didit\Core\RawResponse;

/**
 * The API answered with a non-2xx status. Subclasses exist for the common
 * statuses; {@see ApiStatusException::create()} picks the right one.
 */
class ApiStatusException extends DiditException
{
    /** HTTP status code. */
    public readonly int $status;

    /** Parsed JSON body when the body is JSON, otherwise the raw body string. */
    public readonly mixed $body;

    /** @var array<string, list<string>> Response headers, keys in lower case. */
    public readonly array $headers;

    /** Value of the `X-Request-Id` response header, when present. */
    public readonly ?string $requestId;

    public readonly RawResponse $response;

    final public function __construct(string $message, RawResponse $response)
    {
        parent::__construct($message, $response->status);
        $this->status = $response->status;
        $this->body = $response->parsedBody();
        $this->headers = $response->headers;
        $this->requestId = $response->requestId;
        $this->response = $response;
    }

    /**
     * Builds the exception that matches the response status.
     */
    public static function create(RawResponse $response): self
    {
        $status = $response->status;
        $class = match (true) {
            400 === $status => BadRequestException::class,
            401 === $status => AuthenticationException::class,
            403 === $status => PermissionDeniedException::class,
            404 === $status => NotFoundException::class,
            409 === $status => ConflictException::class,
            422 === $status => UnprocessableEntityException::class,
            429 === $status => RateLimitException::class,
            $status >= 500 => InternalServerException::class,
            default => self::class,
        };

        return new $class(self::buildMessage($response), $response);
    }

    private static function buildMessage(RawResponse $response): string
    {
        $message = \sprintf('HTTP %d', $response->status);
        $reason = $response->reasonPhrase();
        if ('' !== $reason) {
            $message .= ' ' . $reason;
        }
        $detail = self::detail($response->parsedBody());
        if (null !== $detail) {
            $message .= ': ' . $detail;
        }
        if (null !== $response->requestId) {
            $message .= \sprintf(' (request id %s)', $response->requestId);
        }

        return $message;
    }

    private static function detail(mixed $body): ?string
    {
        if (\is_array($body)) {
            foreach (['detail', 'error', 'message', 'error_description'] as $key) {
                if (isset($body[$key]) && \is_string($body[$key]) && '' !== $body[$key]) {
                    return self::truncate($body[$key]);
                }
            }
            $encoded = json_encode($body, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

            return false === $encoded ? null : self::truncate($encoded);
        }
        if (\is_string($body) && '' !== trim($body)) {
            return self::truncate(trim($body));
        }

        return null;
    }

    private static function truncate(string $value): string
    {
        if (\strlen($value) <= 500) {
            return $value;
        }

        return substr($value, 0, 500) . '...';
    }
}
