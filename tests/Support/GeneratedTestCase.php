<?php

declare(strict_types=1);

namespace Didit\Tests\Support;

use Didit\DiditClient;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/**
 * Base of the generated per-operation tests.
 */
abstract class GeneratedTestCase extends TestCase
{
    protected const API_KEY = TestClient::API_KEY;
    protected const ACCOUNT_TOKEN = TestClient::ACCOUNT_TOKEN;

    protected DiditClient $client;

    private MockHttpClient $http;

    protected function setUp(): void
    {
        $support = new TestClient();
        $this->http = $support->http;
        $this->client = $support->client();
    }

    protected function queueJson(int $status, string $json): void
    {
        $this->http->queue($status, $json, ['Content-Type' => 'application/json', 'X-Request-Id' => 'req_generated']);
    }

    protected function queueBinary(int $status, string $bytes, string $contentType): void
    {
        $this->http->queue($status, $bytes, ['Content-Type' => $contentType]);
    }

    protected function queueEmpty(int $status): void
    {
        $this->http->queue($status);
    }

    protected function lastRequest(): RequestInterface
    {
        self::assertSame(0, $this->http->remaining(), 'every queued response was used');
        self::assertCount(1, $this->http->requests, 'exactly one request was sent');

        return $this->http->lastRequest();
    }

    /**
     * @return array<string, string|list<string>>
     */
    protected function queryParams(RequestInterface $request): array
    {
        return Http::queryParams($request);
    }

    /**
     * The JSON body re-encoded without whitespace, keeping `{}` and `[]` apart.
     */
    protected function canonicalBody(RequestInterface $request): string
    {
        return Http::canonicalJson((string) $request->getBody());
    }

    /**
     * @return array<string, list<string>>
     */
    protected function multipartFields(RequestInterface $request): array
    {
        return Http::multipartFields($request);
    }

    protected function decoded(string $json): mixed
    {
        return json_decode($json, true, 512, \JSON_THROW_ON_ERROR | \JSON_BIGINT_AS_STRING);
    }
}
