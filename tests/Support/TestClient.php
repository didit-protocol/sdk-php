<?php

declare(strict_types=1);

namespace Didit\Tests\Support;

use Didit\Core\ClientOptions;
use Didit\Core\HttpTransport;
use Didit\Core\Requester;
use Didit\DiditClient;
use GuzzleHttp\Psr7\HttpFactory;
use Psr\Http\Client\ClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Builds clients around a mocked transport, with sleeping and randomness under test control.
 */
final class TestClient
{
    public const API_KEY = 'test_api_key_123';
    public const ACCOUNT_TOKEN = 'account_token_456';
    public const ORGANIZATION_ID = 'org-7f3a';
    public const APPLICATION_ID = 'app-91c2';

    /** @var list<float> seconds the client slept between retries */
    public array $sleeps = [];

    public float $now = 1_760_702_400.0;

    public function __construct(
        public readonly MockHttpClient $http = new MockHttpClient(),
    ) {
    }

    /**
     * @param array<string, string> $defaultHeaders
     */
    public function client(
        ?ClientOptions $options = null,
        ?LoggerInterface $logger = null,
        ?ClientInterface $httpClient = null,
        float $random = 0.5,
    ): DiditClient {
        $factory = new HttpFactory();
        $requester = new Requester(
            $options ?? self::options(),
            new HttpTransport($httpClient ?? $this->http),
            $factory,
            $factory,
            $logger,
            function (float $seconds): void {
                $this->sleeps[] = $seconds;
            },
            static fn (): float => $random,
            fn (): float => $this->now,
        );

        return DiditClient::fromRequester($requester);
    }

    /**
     * @param array<string, string> $defaultHeaders
     */
    public static function options(
        ?string $apiKey = self::API_KEY,
        int $maxRetries = 2,
        float $timeout = 60.0,
        array $defaultHeaders = [],
        ?string $organizationId = self::ORGANIZATION_ID,
        ?string $applicationId = self::APPLICATION_ID,
        ?string $accountToken = self::ACCOUNT_TOKEN,
        string $baseUrl = ClientOptions::DEFAULT_BASE_URL,
    ): ClientOptions {
        return new ClientOptions(
            apiKey: $apiKey,
            baseUrl: $baseUrl,
            accountBaseUrl: ClientOptions::DEFAULT_ACCOUNT_BASE_URL,
            organizationId: $organizationId,
            applicationId: $applicationId,
            accountToken: $accountToken,
            timeout: $timeout,
            maxRetries: $maxRetries,
            defaultHeaders: $defaultHeaders,
        );
    }
}
