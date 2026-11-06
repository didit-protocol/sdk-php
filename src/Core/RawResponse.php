<?php

declare(strict_types=1);

namespace Didit\Core;

use Psr\Http\Message\ResponseInterface;

/**
 * The HTTP response behind a result: status, headers, request id and the
 * PSR-7 response itself. Every result exposes it through `rawResponse()`.
 */
final class RawResponse
{
    public readonly int $status;

    /** @var array<string, list<string>> Header values, keys in lower case. */
    public readonly array $headers;

    /** Value of the `X-Request-Id` header, when present. */
    public readonly ?string $requestId;

    private ?string $body = null;

    private bool $parsed = false;

    private mixed $parsedBody = null;

    public function __construct(
        public readonly ResponseInterface $psrResponse,
        /** HTTP method of the request. */
        public readonly string $method = 'GET',
        /** Request path, without the query string. */
        public readonly string $path = '',
    ) {
        $this->status = $psrResponse->getStatusCode();
        $headers = [];
        foreach ($psrResponse->getHeaders() as $name => $values) {
            $key = strtolower((string) $name);
            foreach ($values as $value) {
                $headers[$key][] = $value;
            }
        }
        $this->headers = $headers;
        $this->requestId = self::firstHeader($headers, 'x-request-id');
    }

    /**
     * First value of a header, case-insensitive, or null.
     */
    public function header(string $name): ?string
    {
        return self::firstHeader($this->headers, strtolower($name));
    }

    public function reasonPhrase(): string
    {
        return $this->psrResponse->getReasonPhrase();
    }

    /**
     * The response body as a string.
     */
    public function body(): string
    {
        if (null === $this->body) {
            $stream = $this->psrResponse->getBody();
            if ($stream->isSeekable()) {
                $stream->rewind();
            }
            $this->body = $stream->getContents();
        }

        return $this->body;
    }

    /**
     * The body decoded as JSON, or the raw string when it is not JSON, or
     * null when it is empty.
     */
    public function parsedBody(): mixed
    {
        if (!$this->parsed) {
            $this->parsed = true;
            $body = $this->body();
            if ('' === trim($body)) {
                $this->parsedBody = null;
            } else {
                try {
                    $this->parsedBody = json_decode($body, true, 512, \JSON_THROW_ON_ERROR | \JSON_BIGINT_AS_STRING);
                } catch (\JsonException) {
                    $this->parsedBody = $body;
                }
            }
        }

        return $this->parsedBody;
    }

    /**
     * @param array<string, list<string>> $headers
     */
    private static function firstHeader(array $headers, string $key): ?string
    {
        $values = $headers[$key] ?? [];

        return [] === $values ? null : $values[0];
    }
}
