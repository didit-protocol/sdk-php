<?php

declare(strict_types=1);

namespace Didit\Tests\Unit;

use Didit\Core\HttpTransport;
use Didit\DiditClient;
use Didit\Exception\ApiStatusException;
use Didit\Exception\ApiTimeoutException;
use Didit\Exception\ConfigurationException;
use Didit\Exception\InternalServerException;
use Didit\Exception\NotFoundException;
use Didit\RequestOptions;
use Didit\Tests\Support\TestClient;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient as SymfonyMockHttpClient;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Timeouts reach the HTTP client, and the SDK never lets the client follow
 * redirects or throw on error statuses (DESIGN sections 6 and 7).
 *
 * @internal
 */
final class TimeoutTest extends TestCase
{
    private TestClient $support;

    /** @var \ArrayObject<int, mixed> Guzzle history entries */
    private \ArrayObject $history;

    private MockHandler $guzzleMock;

    protected function setUp(): void
    {
        $this->support = new TestClient();
        $this->history = new \ArrayObject();
        $this->guzzleMock = new MockHandler();
    }

    public function testClientTimeoutReachesGuzzle(): void
    {
        $this->guzzleMock->append(self::okResponse());

        $this->support->client(TestClient::options(timeout: 12.5), httpClient: $this->guzzle())->sessions->retrieveDecision('abc');

        $options = $this->guzzleOptions(0);
        self::assertSame(12.5, $options['timeout']);
        self::assertSame(12.5, $options['connect_timeout']);
        self::assertFalse($options['allow_redirects']);
        self::assertFalse($options['http_errors']);
        self::assertSame($options, $this->guzzleMock->getLastOptions());
    }

    public function testRequestTimeoutOverridesTheClientForGuzzle(): void
    {
        $this->guzzleMock->append(self::okResponse(), self::okResponse());
        $client = $this->support->client(TestClient::options(timeout: 30.0), httpClient: $this->guzzle());

        $client->sessions->retrieveDecision('abc', [], new RequestOptions(timeout: 2.5));
        $client->sessions->retrieveDecision('abc');

        self::assertSame(2.5, $this->guzzleOptions(0)['timeout']);
        self::assertSame(2.5, $this->guzzleOptions(0)['connect_timeout']);
        self::assertSame(30.0, $this->guzzleOptions(1)['timeout'], 'the override applies to one request only');
    }

    public function testDefaultAndWithOptionsTimeoutsReachGuzzle(): void
    {
        $this->guzzleMock->append(self::okResponse(), self::okResponse());
        $client = new DiditClient(
            apiKey: TestClient::API_KEY,
            httpClient: $this->guzzle(),
            requestFactory: new HttpFactory(),
            streamFactory: new HttpFactory(),
        );

        $client->sessions->retrieveDecision('abc');
        $client->withOptions(timeout: 4.0)->sessions->retrieveDecision('abc');

        self::assertSame(60.0, $this->guzzleOptions(0)['timeout']);
        self::assertSame(4.0, $this->guzzleOptions(1)['timeout']);
    }

    public function testGuzzleNeverFollowsRedirects(): void
    {
        $this->guzzleMock->append(new Response(302, ['Location' => 'https://elsewhere.example.com/steal']), self::okResponse());
        $client = $this->support->client(TestClient::options(maxRetries: 0), httpClient: $this->guzzle(['allow_redirects' => true]));

        try {
            $client->sessions->retrieveDecision('abc');
            self::fail('no exception');
        } catch (ApiStatusException $e) {
            self::assertSame(302, $e->status);
        }
        self::assertCount(1, $this->history);
        self::assertSame(1, $this->guzzleMock->count(), 'the redirect target was never requested');
    }

    public function testGuzzleErrorStatusesAreMappedByTheSdkNotByGuzzle(): void
    {
        $this->guzzleMock->append(new Response(404, ['Content-Type' => 'application/json'], '{"detail":"Not found."}'));
        $client = $this->support->client(TestClient::options(maxRetries: 0), httpClient: $this->guzzle(['http_errors' => true]));

        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage('Not found.');
        $client->sessions->retrieveDecision('abc');
    }

    public function testGuzzleTimeoutBecomesApiTimeoutException(): void
    {
        $request = new Request('GET', 'https://verification.didit.me/v3/session/abc/decision/');
        $this->guzzleMock->append(new ConnectException(
            'cURL error 28: Operation timed out after 2500 milliseconds with 0 bytes received (see https://curl.haxx.se/libcurl/c/libcurl-errors.html)',
            $request,
            null,
            ['errno' => 28, 'error' => 'Operation timed out after 2500 milliseconds with 0 bytes received'],
        ));
        $client = $this->support->client(TestClient::options(maxRetries: 0, timeout: 2.5), httpClient: $this->guzzle());

        try {
            $client->sessions->retrieveDecision('abc');
            self::fail('no exception');
        } catch (ApiTimeoutException $e) {
            self::assertTrue($e->mayHaveReachedServer);
            self::assertStringStartsWith('Request timed out: cURL error 28: Operation timed out after 2500 milliseconds', $e->getMessage());
            self::assertStringNotContainsString('https://', $e->getMessage());
        }
    }

    public function testTimeoutReachesSymfonyHttpClient(): void
    {
        /** @var list<array<mixed>> $seen */
        $seen = [];
        $symfony = new SymfonyMockHttpClient(static function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen[] = $options;

            return new MockResponse('{"session_id":"abc"}', ['http_code' => 200, 'response_headers' => ['Content-Type: application/json']]);
        });
        $psr18 = new Psr18Client($symfony, new HttpFactory(), new HttpFactory());
        $client = $this->support->client(TestClient::options(timeout: 7.5), httpClient: $psr18);

        $decision = $client->sessions->retrieveDecision('abc');
        $client->sessions->retrieveDecision('abc', [], new RequestOptions(timeout: 1.5));

        self::assertSame('abc', $decision->sessionId);
        self::assertCount(2, $seen);
        self::assertSame(7.5, $seen[0]['timeout']);
        self::assertSame(7.5, $seen[0]['max_duration']);
        self::assertSame(0, $seen[0]['max_redirects']);
        self::assertSame(1.5, $seen[1]['timeout']);
        self::assertSame(1.5, $seen[1]['max_duration']);
        self::assertSame(0, $seen[1]['max_redirects']);
    }

    public function testSymfonyErrorStatusIsMappedAndTimeoutBecomesApiTimeoutException(): void
    {
        $symfony = new SymfonyMockHttpClient([
            new MockResponse('{"detail":"boom"}', ['http_code' => 500, 'response_headers' => ['Content-Type: application/json']]),
            new MockResponse('', ['error' => 'Idle timeout reached for "https://verification.didit.me/v3/session/abc/decision/?q=secret".']),
        ]);
        $client = $this->support->client(TestClient::options(maxRetries: 0), httpClient: new Psr18Client($symfony, new HttpFactory(), new HttpFactory()));

        try {
            $client->sessions->retrieveDecision('abc');
            self::fail('no exception');
        } catch (InternalServerException $e) {
            self::assertSame(['detail' => 'boom'], $e->body);
        }

        try {
            $client->sessions->retrieveDecision('abc');
            self::fail('no exception');
        } catch (ApiTimeoutException $e) {
            self::assertSame('Request timed out: Idle timeout reached for "<url>".', $e->getMessage());
            self::assertTrue($e->mayHaveReachedServer);
        }
    }

    public function testDefaultHttpClientIsGuzzleWhenInstalled(): void
    {
        $default = HttpTransport::defaultClient();

        self::assertInstanceOf(Client::class, $default);
        self::assertInstanceOf(Client::class, (new HttpTransport())->client());
    }

    /**
     * @return iterable<string, array{float}>
     */
    public static function nonPositiveTimeouts(): iterable
    {
        yield 'zero' => [0.0];
        yield 'negative' => [-1.0];
    }

    #[DataProvider('nonPositiveTimeouts')]
    public function testNonPositiveClientTimeoutIsAConfigurationError(float $timeout): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('timeout');
        new DiditClient(apiKey: TestClient::API_KEY, timeout: $timeout, httpClient: $this->support->http, requestFactory: new HttpFactory(), streamFactory: new HttpFactory());
    }

    #[DataProvider('nonPositiveTimeouts')]
    public function testNonPositiveRequestTimeoutFailsBeforeSending(float $timeout): void
    {
        $client = $this->support->client();

        try {
            $client->sessions->retrieveDecision('abc', [], new RequestOptions(timeout: $timeout));
            self::fail('no exception');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString('timeout', $e->getMessage());
        }
        try {
            $client->withOptions(timeout: $timeout);
            self::fail('no exception');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString('timeout', $e->getMessage());
        }
        self::assertSame([], $this->support->http->requests);
    }

    public function testNegativeRequestMaxRetriesFailsBeforeSending(): void
    {
        $this->expectException(ConfigurationException::class);
        try {
            $this->support->client()->sessions->retrieveDecision('abc', [], new RequestOptions(maxRetries: -1));
        } finally {
            self::assertSame([], $this->support->http->requests);
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private function guzzle(array $config = []): Client
    {
        $stack = HandlerStack::create($this->guzzleMock);
        $stack->push(Middleware::history($this->history));

        return new Client(['handler' => $stack] + $config);
    }

    /**
     * The request options Guzzle saw for request number `$index`.
     *
     * @return array<mixed>
     */
    private function guzzleOptions(int $index): array
    {
        $entry = $this->history[$index] ?? null;
        self::assertIsArray($entry, "request {$index} was sent");
        $options = $entry['options'] ?? null;
        self::assertIsArray($options);

        return $options;
    }

    private static function okResponse(): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], '{"session_id":"abc"}');
    }
}
