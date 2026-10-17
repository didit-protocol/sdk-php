<?php

declare(strict_types=1);

namespace Didit\Core;

use Http\Discovery\Psr17FactoryDiscovery;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Configuration and transport shared by the generated {@see \Didit\DiditClient}.
 *
 * The client is immutable and safe to reuse: create one per process.
 */
abstract class BaseClient
{
    /** Set once, by the constructor or {@see fromRequester()}. */
    protected Requester $requester;

    /**
     * @param string|null $apiKey your application's API key; falls back to `DIDIT_API_KEY`
     * @param string|null $baseUrl defaults to `https://verification.didit.me`
     * @param string|null $organizationId fills application-scoped paths; falls back to `DIDIT_ORGANIZATION_ID`
     * @param string|null $applicationId fills application-scoped paths; falls back to `DIDIT_APPLICATION_ID`
     * @param float $timeout seconds per attempt
     * @param int $maxRetries retries after the first attempt (see the README for which requests are retried)
     * @param array<string, string> $defaultHeaders extra headers sent with every request
     * @param ClientInterface|null $httpClient any PSR-18 client; defaults to Guzzle, then Symfony HttpClient, then discovery
     * @param string|null $accountBaseUrl defaults to `https://apx.didit.me/auth/v2`
     * @param string|null $accountToken Bearer token for the `account` resource, from `$didit->account->login()`
     * @param LoggerInterface|null $logger PSR-3 logger for debug lines (method, path, status, duration, request id); `DIDIT_LOG=debug` logs to stderr when no logger is given
     */
    public function __construct(
        #[\SensitiveParameter]
        ?string $apiKey = null,
        ?string $baseUrl = null,
        ?string $organizationId = null,
        ?string $applicationId = null,
        float $timeout = ClientOptions::DEFAULT_TIMEOUT,
        int $maxRetries = ClientOptions::DEFAULT_MAX_RETRIES,
        array $defaultHeaders = [],
        ?ClientInterface $httpClient = null,
        ?string $accountBaseUrl = null,
        #[\SensitiveParameter]
        ?string $accountToken = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        ?LoggerInterface $logger = null,
    ) {
        $options = ClientOptions::resolve(
            apiKey: $apiKey,
            baseUrl: $baseUrl,
            organizationId: $organizationId,
            applicationId: $applicationId,
            timeout: $timeout,
            maxRetries: $maxRetries,
            defaultHeaders: $defaultHeaders,
            accountBaseUrl: $accountBaseUrl,
            accountToken: $accountToken,
        );
        $this->requester = new Requester(
            $options,
            new HttpTransport($httpClient),
            $requestFactory ?? Psr17FactoryDiscovery::findRequestFactory(),
            $streamFactory ?? Psr17FactoryDiscovery::findStreamFactory(),
            $logger ?? self::loggerFromEnvironment(),
        );
        $this->initResources();
    }

    /**
     * @internal builds a client around an existing requester (tests, {@see withOptions()})
     */
    public static function fromRequester(Requester $requester): static
    {
        $client = (new \ReflectionClass(static::class))->newInstanceWithoutConstructor();
        $client->requester = $requester;
        $client->initResources();

        return $client;
    }

    /**
     * A copy of this client with some options changed; null keeps the current
     * value. The copy shares the HTTP client.
     *
     * @param array<string, string>|null $defaultHeaders replaces the default headers
     */
    public function withOptions(
        #[\SensitiveParameter]
        ?string $apiKey = null,
        ?string $baseUrl = null,
        ?string $organizationId = null,
        ?string $applicationId = null,
        ?float $timeout = null,
        ?int $maxRetries = null,
        ?array $defaultHeaders = null,
        ?string $accountBaseUrl = null,
        #[\SensitiveParameter]
        ?string $accountToken = null,
    ): static {
        $current = $this->requester->options;
        $options = new ClientOptions(
            apiKey: $apiKey ?? $current->apiKey,
            baseUrl: null === $baseUrl ? $current->baseUrl : ClientOptions::normalizeBaseUrl($baseUrl),
            accountBaseUrl: null === $accountBaseUrl ? $current->accountBaseUrl : ClientOptions::normalizeBaseUrl($accountBaseUrl),
            organizationId: $organizationId ?? $current->organizationId,
            applicationId: $applicationId ?? $current->applicationId,
            accountToken: $accountToken ?? $current->accountToken,
            timeout: $timeout ?? $current->timeout,
            maxRetries: $maxRetries ?? $current->maxRetries,
            defaultHeaders: $defaultHeaders ?? $current->defaultHeaders,
        );

        return static::fromRequester($this->requester->withOptions($options));
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['options' => $this->requester->options->__debugInfo()];
    }

    abstract protected function initResources(): void;

    private static function loggerFromEnvironment(): ?LoggerInterface
    {
        $level = $_ENV['DIDIT_LOG'] ?? $_SERVER['DIDIT_LOG'] ?? getenv('DIDIT_LOG');

        return \is_string($level) && 'debug' === strtolower($level) ? new StderrLogger() : null;
    }
}
