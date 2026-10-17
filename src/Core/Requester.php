<?php

declare(strict_types=1);

namespace Didit\Core;

use Didit\Exception\ApiConnectionException;
use Didit\Exception\ApiStatusException;
use Didit\Exception\ConfigurationException;
use Didit\Exception\DiditException;
use Didit\RequestOptions;
use Didit\Version;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Builds, sends and retries API requests.
 *
 * @internal
 */
final class Requester
{
    private const IDEMPOTENT_METHODS = ['GET', 'HEAD', 'PUT', 'DELETE', 'OPTIONS'];
    private const INITIAL_BACKOFF = 0.5;
    private const MAX_BACKOFF = 8.0;
    private const MAX_RETRY_AFTER = 60.0;

    /** @var \Closure(float): void */
    private readonly \Closure $sleep;

    /** @var \Closure(): float */
    private readonly \Closure $random;

    /** @var \Closure(): float */
    private readonly \Closure $now;

    /**
     * @param (callable(float): void)|null $sleep sleeps for the given seconds
     * @param (callable(): float)|null $random returns a float in [0, 1)
     * @param (callable(): float)|null $now returns the current unix time in seconds
     */
    public function __construct(
        public readonly ClientOptions $options,
        private readonly HttpTransport $transport,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly ?LoggerInterface $logger = null,
        ?callable $sleep = null,
        ?callable $random = null,
        ?callable $now = null,
    ) {
        $this->sleep = null === $sleep ? static function (float $seconds): void {
            if ($seconds > 0) {
                usleep((int) round($seconds * 1_000_000));
            }
        } : $sleep(...);
        $this->random = null === $random ? static fn (): float => mt_rand() / (mt_getrandmax() + 1) : $random(...);
        $this->now = null === $now ? static fn (): float => microtime(true) : $now(...);
    }

    /**
     * The same transport and factories with other options.
     */
    public function withOptions(ClientOptions $options): self
    {
        return new self($options, $this->transport, $this->requestFactory, $this->streamFactory, $this->logger, $this->sleep, $this->random, $this->now);
    }

    /**
     * Sends an operation and returns its 2xx response.
     *
     * @param array<string, string|int> $pathParams
     * @param array<string, mixed> $params
     *
     * @throws DiditException
     */
    public function request(Operation $operation, array $pathParams, array $params, ?RequestOptions $requestOptions): RawResponse
    {
        $options = $this->options->merge($requestOptions);
        $path = $this->expandPath($operation, $pathParams, $options);

        $query = [];
        $headers = [];
        $body = [];
        $leftoversToQuery = [] === $operation->bodyTypes && \in_array($operation->method, ['GET', 'HEAD', 'DELETE'], true);
        foreach ($params as $key => $value) {
            if (isset($operation->query[$key])) {
                $query[$operation->query[$key]] = $value;
            } elseif (isset($operation->headers[$key])) {
                if (null !== $value) {
                    $headers[$operation->headers[$key]] = self::scalarString($value, $key);
                }
            } elseif ($leftoversToQuery) {
                $query[$key] = $value;
            } else {
                $body[$key] = $value;
            }
        }
        foreach ($requestOptions->extraQuery ?? [] as $key => $value) {
            $query[$key] = $value;
        }

        $bodyFactory = null;
        if ([] !== $operation->bodyTypes || [] !== $body) {
            $bodyFactory = $this->bodyFactory($operation, $body, $headers);
        }

        $url = $this->baseUrl($operation, $options) . $path . self::queryString($query);

        return $this->send($operation, $url, $path, $headers, $bodyFactory, $options);
    }

    /**
     * Sends a list operation and returns its first page.
     *
     * @template T
     *
     * @param array<string, string|int> $pathParams
     * @param array<string, mixed> $params
     * @param callable(mixed): (T|null) $item
     *
     * @return Page<T>
     */
    public function requestPage(Operation $operation, array $pathParams, array $params, ?RequestOptions $requestOptions, callable $item): Page
    {
        $response = $this->request($operation, $pathParams, $params, $requestOptions);
        $options = $this->options->merge($requestOptions);

        return Page::fromResponse($response, $item, $this->nextPageFetcher($operation, $options, $item));
    }

    /**
     * @template T
     *
     * @param callable(mixed): (T|null) $item
     *
     * @return \Closure(string): Page<T>
     */
    private function nextPageFetcher(Operation $operation, ClientOptions $options, callable $item): \Closure
    {
        return function (string $next) use ($operation, $options, $item): Page {
            [$url, $path] = $this->resolveNextUrl($next, $this->baseUrl($operation, $options));
            $response = $this->send($operation, $url, $path, [], null, $options);

            return Page::fromResponse($response, $item, $this->nextPageFetcher($operation, $options, $item));
        };
    }

    /**
     * Resolves a pagination link against the base URL. A link to another
     * origin is refused, so the API key never leaves the configured origin.
     *
     * @return array{string, string} [absolute URL, path]
     */
    public function resolveNextUrl(string $next, string $baseUrl): array
    {
        $link = parse_url($next);
        $base = parse_url($baseUrl);
        if (false === $link || false === $base || !isset($base['scheme'], $base['host'])) {
            throw new DiditException('Invalid pagination link.');
        }
        if (isset($link['host'])) {
            $linkScheme = strtolower($link['scheme'] ?? '');
            $baseScheme = strtolower($base['scheme']);
            $sameHost = strtolower($link['host']) === strtolower($base['host']);
            $basePort = self::port($base['port'] ?? null, $baseScheme);
            if ($linkScheme === $baseScheme) {
                $sameOrigin = $sameHost && self::port($link['port'] ?? null, $linkScheme) === $basePort;
            } else {
                // An http link to the same host is followed over the configured https origin.
                $sameOrigin = $sameHost && 'http' === $linkScheme && 'https' === $baseScheme
                    && !isset($link['port']) && 443 === $basePort;
            }
            if (!$sameOrigin || isset($link['user']) || isset($link['pass'])) {
                throw new DiditException('Refusing to follow a pagination link to another origin.');
            }
        }
        $origin = $base['scheme'] . '://' . $base['host'] . (isset($base['port']) ? ':' . $base['port'] : '');
        $path = $link['path'] ?? '/';
        if (!str_starts_with($path, '/')) {
            $path = '/' . $path;
        }

        return [$origin . $path . (isset($link['query']) ? '?' . $link['query'] : ''), $path];
    }

    /**
     * @param array<string, string> $operationHeaders
     * @param (\Closure(): array{string, string})|null $bodyFactory returns [content type, body]
     */
    private function send(Operation $operation, string $url, string $path, array $operationHeaders, ?\Closure $bodyFactory, ClientOptions $options): RawResponse
    {
        $headers = $this->headers($operation, $options, $operationHeaders);
        $body = null === $bodyFactory ? null : $bodyFactory();
        $method = $operation->method;

        for ($attempt = 0; ; ++$attempt) {
            $request = $this->buildRequest($method, $url, $headers, $body);
            $started = ($this->now)();
            try {
                $psrResponse = $this->transport->send($request, $options->timeout);
            } catch (ApiConnectionException $e) {
                $this->log($method, $path, null, $started, $attempt);
                if ($attempt < $options->maxRetries && $this->shouldRetryConnection($method, $e)) {
                    ($this->sleep)($this->backoff($attempt, null));
                    continue;
                }

                throw $e;
            }

            $response = new RawResponse($psrResponse, $method, $path);
            $this->log($method, $path, $response, $started, $attempt);
            if ($response->status >= 200 && $response->status < 300) {
                return $response;
            }
            if ($attempt < $options->maxRetries && $this->shouldRetryStatus($method, $response->status)) {
                ($this->sleep)($this->backoff($attempt, $response->header('retry-after')));
                continue;
            }

            throw ApiStatusException::create($response);
        }
    }

    public function shouldRetryConnection(string $method, ApiConnectionException $e): bool
    {
        if (\in_array($method, self::IDEMPOTENT_METHODS, true)) {
            return true;
        }

        // POST and PATCH: only when the request cannot have reached the server.
        return !$e->mayHaveReachedServer;
    }

    public function shouldRetryStatus(string $method, int $status): bool
    {
        if (429 === $status) {
            return true;
        }
        if (!\in_array($method, self::IDEMPOTENT_METHODS, true)) {
            return false;
        }

        return 408 === $status || $status >= 500;
    }

    /**
     * Seconds to wait before retry number `$attempt + 1`.
     */
    public function backoff(int $attempt, ?string $retryAfter): float
    {
        $fromHeader = $this->retryAfterSeconds($retryAfter);
        if (null !== $fromHeader) {
            return $fromHeader;
        }
        $cap = min(self::MAX_BACKOFF, self::INITIAL_BACKOFF * (2 ** $attempt));

        return $cap * ($this->random)();
    }

    private function retryAfterSeconds(?string $value): ?float
    {
        if (null === $value) {
            return null;
        }
        $value = trim($value);
        if (1 === preg_match('/^\d+(\.\d+)?$/', $value)) {
            return min(self::MAX_RETRY_AFTER, (float) $value);
        }
        $date = \DateTimeImmutable::createFromFormat(\DATE_RFC7231, $value, new \DateTimeZone('UTC'));
        if (false === $date) {
            return null;
        }

        return min(self::MAX_RETRY_AFTER, max(0.0, (float) $date->getTimestamp() - ($this->now)()));
    }

    /**
     * @param array<string, string|int> $pathParams
     */
    private function expandPath(Operation $operation, array $pathParams, ClientOptions $options): string
    {
        if ($operation->applicationScope) {
            if (null === $options->organizationId || null === $options->applicationId) {
                throw new ConfigurationException('This operation is scoped to an application: pass organizationId and applicationId to DiditClient, or set DIDIT_ORGANIZATION_ID and DIDIT_APPLICATION_ID.');
            }
            $pathParams['organization_id'] = $options->organizationId;
            $pathParams['application_id'] = $options->applicationId;
        }

        return (string) preg_replace_callback('/\{([^}]+)\}/', static function (array $match) use ($pathParams): string {
            $value = $pathParams[$match[1]] ?? null;
            if (null === $value || '' === (string) $value) {
                throw new \InvalidArgumentException(\sprintf('Missing path parameter "%s".', $match[1]));
            }

            return rawurlencode((string) $value);
        }, $operation->path);
    }

    private function baseUrl(Operation $operation, ClientOptions $options): string
    {
        return Operation::BASE_ACCOUNT === $operation->base ? $options->accountBaseUrl : $options->baseUrl;
    }

    /**
     * @param array<string, string> $operationHeaders
     *
     * @return array<string, string>
     */
    private function headers(Operation $operation, ClientOptions $options, array $operationHeaders): array
    {
        $headers = [
            'Accept' => $operation->accept,
            'User-Agent' => self::userAgent(),
            'X-Didit-Client' => 'php/' . Version::VERSION,
        ];
        foreach ($options->defaultHeaders as $name => $value) {
            // The SDK owns the credential headers.
            if (!\in_array(strtolower($name), ['x-api-key', 'authorization'], true)) {
                $headers[$name] = $value;
            }
        }
        foreach ($operationHeaders as $name => $value) {
            $headers[$name] = $value;
        }

        switch ($operation->auth) {
            case Operation::AUTH_API_KEY:
                if (null === $options->apiKey) {
                    throw new ConfigurationException('Missing API key: pass apiKey to DiditClient or set the DIDIT_API_KEY environment variable.');
                }
                $headers['x-api-key'] = $options->apiKey;
                break;
            case Operation::AUTH_ACCOUNT_TOKEN:
                if (null === $options->accountToken) {
                    throw new ConfigurationException('Missing account token: call $didit->account->login(...) and pass the access token as accountToken.');
                }
                $headers['Authorization'] = 'Bearer ' . $options->accountToken;
                break;
        }

        return $headers;
    }

    public static function userAgent(): string
    {
        return \sprintf('didit-php/%s (PHP %s; %s)', Version::VERSION, \PHP_VERSION, \PHP_OS_FAMILY);
    }

    /**
     * @param array<string, string> $headers
     * @param array{string, string}|null $body
     */
    private function buildRequest(string $method, string $url, array $headers, ?array $body): RequestInterface
    {
        $request = $this->requestFactory->createRequest($method, $url);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        if (null !== $body) {
            $request = $request
                ->withHeader('Content-Type', $body[0])
                ->withBody($this->streamFactory->createStream($body[1]));
        }

        return $request;
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     *
     * @return \Closure(): array{string, string}
     */
    private function bodyFactory(Operation $operation, array $body, array $headers): \Closure
    {
        $types = $operation->bodyTypes;
        $useMultipart = \in_array('multipart', $types, true)
            && (!\in_array('json', $types, true) || Multipart::containsFile($body));

        if ($useMultipart) {
            return static function () use ($body): array {
                $boundary = 'didit-' . bin2hex(random_bytes(16));

                return ['multipart/form-data; boundary=' . $boundary, Multipart::encode($body, $boundary)];
            };
        }

        return static fn (): array => ['application/json', Json::encode([] === $body ? new \stdClass() : $body)];
    }

    /**
     * @param array<string, mixed> $query
     */
    private static function queryString(array $query): string
    {
        $pairs = [];
        foreach ($query as $key => $value) {
            if (null === $value) {
                continue;
            }
            $pairs[] = rawurlencode((string) $key) . '=' . rawurlencode(self::scalarString($value, (string) $key));
        }

        return [] === $pairs ? '' : '?' . implode('&', $pairs);
    }

    private static function scalarString(mixed $value, string $key): string
    {
        return match (true) {
            \is_bool($value) => $value ? 'true' : 'false',
            \is_string($value) => $value,
            \is_int($value) => (string) $value,
            \is_float($value) => Json::encode($value),
            $value instanceof \DateTimeInterface => $value->format(\DateTimeInterface::ATOM),
            $value instanceof \Stringable => (string) $value,
            default => throw new \InvalidArgumentException(\sprintf('Parameter "%s" must be a scalar value.', $key)),
        };
    }

    private function log(string $method, string $path, ?RawResponse $response, float $started, int $attempt): void
    {
        if (null === $this->logger) {
            return;
        }
        $this->logger->debug(\sprintf(
            '%s %s %s %dms%s%s',
            $method,
            $path,
            null === $response ? 'connection-error' : (string) $response->status,
            (int) round((($this->now)() - $started) * 1000),
            null === $response?->requestId ? '' : ' request_id=' . $response->requestId,
            $attempt > 0 ? ' retry=' . $attempt : '',
        ));
    }

    private static function port(?int $port, string $scheme): int
    {
        return $port ?? ('http' === $scheme ? 80 : 443);
    }
}
