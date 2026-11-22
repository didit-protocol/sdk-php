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
     *
     * @param list<string> $redact credentials removed from the message should the server echo them
     */
    public static function create(RawResponse $response, #[\SensitiveParameter] array $redact = []): self
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

        return new $class(self::redact(self::buildMessage($response, $redact), $redact), $response);
    }

    /**
     * The text with every secret removed, in raw and JSON-escaped form.
     * Longest form first, so a secret that is a prefix of another cannot leave part of the longer one behind.
     *
     * @internal
     *
     * @param list<string> $secrets
     */
    public static function redact(string $message, #[\SensitiveParameter] array $secrets): string
    {
        $forms = [];
        foreach ($secrets as $secret) {
            if ('' === $secret) {
                continue;
            }
            $forms[$secret] = true;
            foreach ([\JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE, \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE] as $flags) {
                $escaped = substr(json_encode($secret, $flags | \JSON_THROW_ON_ERROR), 1, -1);
                if ('' !== $escaped) {
                    $forms[$escaped] = true;
                }
            }
        }
        $forms = array_map('strval', array_keys($forms));
        usort($forms, static fn (string $a, string $b): int => \strlen($b) <=> \strlen($a));
        foreach ($forms as $form) {
            $message = str_replace($form, '[redacted]', $message);
        }

        return $message;
    }

    /**
     * @param list<string> $redact
     */
    private static function buildMessage(RawResponse $response, #[\SensitiveParameter] array $redact): string
    {
        $message = \sprintf('HTTP %d', $response->status);
        $reason = $response->reasonPhrase();
        if ('' !== $reason) {
            $message .= ' ' . $reason;
        }
        $detail = self::detail($response->parsedBody(), $redact);
        if (null !== $detail) {
            $message .= ': ' . $detail;
        }
        if (null !== $response->requestId) {
            $message .= \sprintf(' (request id %s)', $response->requestId);
        }

        return $message;
    }

    /**
     * Secrets are removed before the length cap, so a truncated prefix of one never survives.
     *
     * @param list<string> $redact
     */
    private static function detail(mixed $body, #[\SensitiveParameter] array $redact): ?string
    {
        if (\is_array($body)) {
            foreach (['detail', 'error', 'message', 'error_description'] as $key) {
                if (isset($body[$key]) && \is_string($body[$key]) && '' !== $body[$key]) {
                    return self::truncate(self::redact($body[$key], $redact));
                }
            }
            $encoded = json_encode($body, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

            return false === $encoded ? null : self::truncate(self::redact($encoded, $redact));
        }
        if (\is_string($body) && '' !== trim($body)) {
            return self::truncate(self::redact(trim($body), $redact));
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
