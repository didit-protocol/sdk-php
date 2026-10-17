<?php

declare(strict_types=1);

namespace Didit\Tests\Unit;

use Didit\Core\HttpTransport;
use Didit\Core\Requester;
use Didit\DiditClient;
use Didit\Exception\ApiConnectionException;
use Didit\Exception\ApiStatusException;
use Didit\Exception\ApiTimeoutException;
use Didit\Exception\InternalServerException;
use Didit\Exception\RateLimitException;
use Didit\RequestOptions;
use Didit\Tests\Support\MockHttpClient;
use Didit\Tests\Support\TestClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;

/**
 * The retry policy of DESIGN section 7.
 *
 * TestClient records sleeps instead of sleeping and returns 0.5 as the jitter,
 * so the backoff before retry n (n from 1) is 0.5 * 2^(n-1) * 0.5 seconds.
 *
 * @internal
 */
final class RetryTest extends TestCase
{
    private TestClient $support;

    protected function setUp(): void
    {
        $this->support = new TestClient();
    }

    /**
     * @return iterable<string, array{string, \Closure(DiditClient): mixed}>
     */
    public static function idempotentCalls(): iterable
    {
        yield 'GET' => ['GET', static fn (DiditClient $c): mixed => $c->sessions->retrieveDecision('abc')];
        yield 'PUT' => ['PUT', static fn (DiditClient $c): mixed => $c->travelRule->updateSettings(['legal_name' => 'Example VASP'])];
        yield 'DELETE' => ['DELETE', static fn (DiditClient $c): mixed => $c->workflows->delete('wf-1')];
    }

    /**
     * @return iterable<string, array{string, \Closure(DiditClient): mixed}>
     */
    public static function nonIdempotentCalls(): iterable
    {
        yield 'POST' => ['POST', static fn (DiditClient $c): mixed => $c->sessions->create(['workflow_id' => 'wf'])];
        yield 'PATCH' => ['PATCH', static fn (DiditClient $c): mixed => $c->sessions->updateStatus('abc', ['new_status' => 'Approved'])];
    }

    /**
     * @return iterable<string, array{string, \Closure(DiditClient): mixed, int}>
     */
    public static function idempotentCallsAndRetriedStatuses(): iterable
    {
        foreach (self::idempotentCalls() as $name => [$method, $call]) {
            foreach ([408, 429, 500, 502, 503, 504] as $status) {
                yield "{$name} {$status}" => [$method, $call, $status];
            }
        }
    }

    /**
     * @param \Closure(DiditClient): mixed $call
     */
    #[DataProvider('idempotentCallsAndRetriedStatuses')]
    public function testIdempotentRequestsAreRetriedOnRetryableStatuses(string $method, \Closure $call, int $status): void
    {
        $this->support->http->queueJson($status, ['detail' => 'try again']);
        $this->support->http->queueJson(200, []);

        $call($this->support->client());

        self::assertCount(2, $this->support->http->requests);
        foreach ($this->support->http->requests as $request) {
            self::assertSame($method, $request->getMethod());
        }
        self::assertSame([0.25], $this->support->sleeps);
    }

    /**
     * @param \Closure(DiditClient): mixed $call
     */
    #[DataProvider('idempotentCalls')]
    public function testRetriesStopAfterMaxRetriesAndTheLastErrorIsThrown(string $method, \Closure $call): void
    {
        $this->support->http->queueJson(503, ['detail' => 'first']);
        $this->support->http->queueJson(502, ['detail' => 'second']);
        $this->support->http->queueJson(500, ['detail' => 'third'], ['X-Request-Id' => 'req_last']);

        try {
            $call($this->support->client());
            self::fail('no exception');
        } catch (InternalServerException $e) {
            self::assertSame(500, $e->status);
            self::assertSame('req_last', $e->requestId);
        }
        self::assertCount(3, $this->support->http->requests, 'default maxRetries 2 means 3 attempts');
        self::assertSame(0, $this->support->http->remaining());
        self::assertSame([0.25, 0.5], $this->support->sleeps);
        self::assertSame($method, $this->support->http->lastRequest()->getMethod());
    }

    /**
     * @param \Closure(DiditClient): mixed $call
     */
    #[DataProvider('idempotentCalls')]
    public function testIdempotentRequestsAreRetriedOnConnectionErrorsAndTimeouts(string $method, \Closure $call): void
    {
        $this->support->http->queueException(self::guzzleError(ConnectException::class, 7, 'Failed to connect to verification.didit.me port 443: Connection refused'));
        $this->support->http->queueException(self::guzzleError(ConnectException::class, 28, 'Operation timed out after 60001 milliseconds with 0 bytes received'));
        $this->support->http->queueJson(200, []);

        $call($this->support->client());

        self::assertCount(3, $this->support->http->requests);
        self::assertSame([0.25, 0.5], $this->support->sleeps);
    }

    public function testConnectionErrorIsThrownWhenRetriesRunOut(): void
    {
        for ($i = 0; $i < 3; ++$i) {
            $this->support->http->queueException(self::guzzleError(ConnectException::class, 28, 'Operation timed out after 60001 milliseconds with 0 bytes received'));
        }

        $this->expectException(ApiTimeoutException::class);
        try {
            $this->support->client()->sessions->retrieveDecision('abc');
        } finally {
            self::assertCount(3, $this->support->http->requests);
            self::assertSame([0.25, 0.5], $this->support->sleeps);
        }
    }

    /**
     * @return iterable<string, array{string, \Closure(DiditClient): mixed, int}>
     */
    public static function nonIdempotentCallsAndStatuses(): iterable
    {
        foreach (self::nonIdempotentCalls() as $name => [$method, $call]) {
            foreach ([408, 500, 502, 503, 504] as $status) {
                yield "{$name} {$status}" => [$method, $call, $status];
            }
        }
    }

    /**
     * @param \Closure(DiditClient): mixed $call
     */
    #[DataProvider('nonIdempotentCallsAndStatuses')]
    public function testPostAndPatchAreNotRetriedAfterAStatusThatMayHaveBeenProcessed(string $method, \Closure $call, int $status): void
    {
        $this->support->http->queueJson($status, ['detail' => 'failed']);
        $this->support->http->queueJson(200, []);

        try {
            $call($this->support->client());
            self::fail('no exception');
        } catch (ApiStatusException $e) {
            self::assertSame($status, $e->status);
        }
        self::assertCount(1, $this->support->http->requests);
        self::assertSame($method, $this->support->http->lastRequest()->getMethod());
        self::assertSame([], $this->support->sleeps);
    }

    /**
     * @return iterable<string, array{\Closure(DiditClient): mixed, \Throwable}>
     */
    public static function nonIdempotentCallsAndFailuresAfterSending(): iterable
    {
        foreach (self::nonIdempotentCalls() as $name => [, $call]) {
            yield "{$name} read timeout" => [$call, self::guzzleError(ConnectException::class, 28, 'Operation timed out after 60001 milliseconds with 0 bytes received')];
            yield "{$name} empty reply" => [$call, self::guzzleError(ConnectException::class, 52, 'Empty reply from server')];
            yield "{$name} connection reset" => [$call, self::guzzleError(RequestException::class, 56, 'Recv failure: Connection reset by peer')];
            yield "{$name} psr-18 connection reset" => [$call, self::networkError('Connection reset by peer')];
            yield "{$name} psr-18 timeout" => [$call, self::networkError('Idle timeout reached')];
        }
    }

    /**
     * @param \Closure(DiditClient): mixed $call
     */
    #[DataProvider('nonIdempotentCallsAndFailuresAfterSending')]
    public function testPostAndPatchAreNotRetriedAfterAFailureThatMayHaveReachedTheServer(\Closure $call, \Throwable $failure): void
    {
        $this->support->http->queueException($failure);
        $this->support->http->queueJson(200, []);

        try {
            $call($this->support->client());
            self::fail('no exception');
        } catch (ApiConnectionException $e) {
            self::assertTrue($e->mayHaveReachedServer);
            self::assertSame($failure, $e->getPrevious());
        }
        self::assertCount(1, $this->support->http->requests);
        self::assertSame([], $this->support->sleeps);
    }

    /**
     * @return iterable<string, array{\Closure(DiditClient): mixed, \Throwable}>
     */
    public static function nonIdempotentCallsAndFailuresBeforeSending(): iterable
    {
        foreach (self::nonIdempotentCalls() as $name => [, $call]) {
            yield "{$name} errno 6" => [$call, self::guzzleError(ConnectException::class, 6, 'Could not resolve host: verification.didit.me')];
            yield "{$name} errno 7" => [$call, self::guzzleError(ConnectException::class, 7, 'Failed to connect to verification.didit.me port 443: Connection refused')];
            yield "{$name} errno 35" => [$call, self::guzzleError(ConnectException::class, 35, 'SSL connect error')];
            yield "{$name} connect timeout" => [$call, self::guzzleError(ConnectException::class, 28, 'Connection timed out after 10001 milliseconds')];
            yield "{$name} psr-18 resolve failure" => [$call, self::networkError('Could not resolve host: verification.didit.me')];
        }
    }

    /**
     * @param \Closure(DiditClient): mixed $call
     */
    #[DataProvider('nonIdempotentCallsAndFailuresBeforeSending')]
    public function testPostAndPatchAreRetriedWhenTheConnectionFailedBeforeSending(\Closure $call, \Throwable $failure): void
    {
        $this->support->http->queueException($failure);
        $this->support->http->queueException($failure);
        $this->support->http->queueJson(200, []);

        $call($this->support->client());

        self::assertCount(3, $this->support->http->requests);
        self::assertSame([0.25, 0.5], $this->support->sleeps);
    }

    /**
     * @param \Closure(DiditClient): mixed $call
     */
    #[DataProvider('nonIdempotentCalls')]
    public function testPostAndPatchAreRetriedOn429(string $method, \Closure $call): void
    {
        $this->support->http->queueJson(429, ['detail' => 'slow down']);
        $this->support->http->queueJson(429, ['detail' => 'slow down']);
        $this->support->http->queueJson(200, []);

        $call($this->support->client());

        self::assertCount(3, $this->support->http->requests);
        foreach ($this->support->http->requests as $request) {
            self::assertSame($method, $request->getMethod());
        }
        self::assertSame([0.25, 0.5], $this->support->sleeps);
    }

    public function testRetriedPostSendsTheSameBody(): void
    {
        $this->support->http->queueJson(429, []);
        $this->support->http->queueJson(201, ['session_id' => 's']);

        $this->support->client()->sessions->create(['workflow_id' => 'wf', 'vendor_data' => 'u1']);

        [$first, $second] = $this->support->http->requests;
        self::assertSame('{"workflow_id":"wf","vendor_data":"u1"}', (string) $first->getBody());
        self::assertSame((string) $first->getBody(), (string) $second->getBody());
        self::assertSame($first->getHeaders(), $second->getHeaders());
    }

    public function test429IsThrownWhenRetriesRunOut(): void
    {
        for ($i = 0; $i < 3; ++$i) {
            $this->support->http->queueJson(429, ['detail' => 'slow down']);
        }

        $this->expectException(RateLimitException::class);
        try {
            $this->support->client()->sessions->create(['workflow_id' => 'wf']);
        } finally {
            self::assertCount(3, $this->support->http->requests);
        }
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function neverRetriedStatuses(): iterable
    {
        foreach ([400, 401, 403, 404, 405, 409, 410, 422, 451] as $status) {
            yield (string) $status => [$status];
        }
    }

    #[DataProvider('neverRetriedStatuses')]
    public function testClientErrorsOtherThan408And429AreNeverRetried(int $status): void
    {
        $this->support->http->queueJson($status, ['detail' => 'no']);
        $this->support->http->queueJson(200, []);

        try {
            $this->support->client()->sessions->retrieveDecision('abc');
            self::fail('no exception');
        } catch (ApiStatusException $e) {
            self::assertSame($status, $e->status);
        }
        self::assertCount(1, $this->support->http->requests);
        self::assertSame([], $this->support->sleeps);
    }

    public function testMaxRetriesZeroDisablesRetries(): void
    {
        $this->support->http->queueJson(503, []);
        $this->support->http->queueException(self::guzzleError(ConnectException::class, 7, 'Connection refused'));

        $client = $this->support->client(TestClient::options(maxRetries: 0));
        try {
            $client->sessions->retrieveDecision('abc');
            self::fail('no exception');
        } catch (InternalServerException) {
        }
        try {
            $client->sessions->retrieveDecision('abc');
            self::fail('no exception');
        } catch (ApiConnectionException) {
        }

        self::assertCount(2, $this->support->http->requests);
        self::assertSame([], $this->support->sleeps);
    }

    public function testPerRequestMaxRetriesOverridesTheClient(): void
    {
        $this->support->http->queueJson(503, []);

        try {
            $this->support->client()->sessions->retrieveDecision('abc', [], new RequestOptions(maxRetries: 0));
            self::fail('no exception');
        } catch (InternalServerException) {
        }
        self::assertCount(1, $this->support->http->requests, 'the client default of 2 retries is overridden by 0');
        self::assertSame([], $this->support->sleeps);

        for ($i = 0; $i < 5; ++$i) {
            $this->support->http->queueJson(503, []);
        }
        $this->support->http->queueJson(200, []);

        $this->support->client(TestClient::options(maxRetries: 0))->sessions->retrieveDecision('abc', [], new RequestOptions(maxRetries: 5));

        self::assertCount(7, $this->support->http->requests, '1 + 6 attempts');
        self::assertSame(0, $this->support->http->remaining());
        self::assertSame([0.25, 0.5, 1.0, 2.0, 4.0], $this->support->sleeps);
    }

    public function testBackoffIsExponentialWithFullJitterAndCappedAt8Seconds(): void
    {
        for ($i = 0; $i < 7; ++$i) {
            $this->support->http->queueJson(503, []);
        }
        $this->support->http->queueJson(200, []);

        $this->support->client(TestClient::options(maxRetries: 7))->sessions->retrieveDecision('abc');

        // 0.5 * 2^n seconds, capped at 8, times the jitter (0.5).
        self::assertSame([0.25, 0.5, 1.0, 2.0, 4.0, 4.0, 4.0], $this->support->sleeps);
    }

    public function testBackoffBounds(): void
    {
        $high = self::requester(0.999999);
        self::assertEqualsWithDelta(0.5 * 0.999999, $high->backoff(0, null), 1e-12);
        self::assertEqualsWithDelta(1.0 * 0.999999, $high->backoff(1, null), 1e-12);
        self::assertEqualsWithDelta(4.0 * 0.999999, $high->backoff(3, null), 1e-12);
        foreach ([4, 5, 10, 62, 100, 2000] as $attempt) {
            $delay = $high->backoff($attempt, null);
            self::assertLessThan(8.0, $delay, "attempt {$attempt}");
            self::assertEqualsWithDelta(8.0 * 0.999999, $delay, 1e-12, "attempt {$attempt}");
        }

        $zero = self::requester(0.0);
        self::assertSame(0.0, $zero->backoff(0, null), 'full jitter goes down to 0');
        self::assertSame(0.0, $zero->backoff(9, null));
    }

    /**
     * @return iterable<string, array{string, float}>
     */
    public static function retryAfterValues(): iterable
    {
        yield 'seconds' => ['3', 3.0];
        yield 'zero' => ['0', 0.0];
        yield 'fraction' => ['1.5', 1.5];
        yield 'padded' => [' 7 ', 7.0];
        yield 'seconds capped at 60' => ['120', 60.0];
        yield 'http date' => [gmdate(\DATE_RFC7231, 1_760_702_400 + 7), 7.0];
        yield 'http date capped at 60' => [gmdate(\DATE_RFC7231, 1_760_702_400 + 3600), 60.0];
        yield 'http date in the past' => [gmdate(\DATE_RFC7231, 1_760_702_400 - 30), 0.0];
        yield 'invalid falls back to backoff' => ['soon', 0.25];
        yield 'negative falls back to backoff' => ['-5', 0.25];
        yield 'empty falls back to backoff' => ['', 0.25];
        yield 'garbage date falls back to backoff' => ['Fri, 99 Foo 2026 25:61:00 GMT', 0.25];
    }

    #[DataProvider('retryAfterValues')]
    public function testRetryAfterIsHonoured(string $retryAfter, float $expectedSleep): void
    {
        self::assertSame(1_760_702_400.0, $this->support->now, 'the fixed clock the HTTP dates are relative to');
        $this->support->http->queueJson(429, ['detail' => 'slow down'], ['Retry-After' => $retryAfter]);
        $this->support->http->queueJson(200, []);

        $this->support->client()->sessions->retrieveDecision('abc');

        self::assertCount(2, $this->support->http->requests);
        self::assertSame([$expectedSleep], $this->support->sleeps);
    }

    public function testRetryAfterOn503ForGet(): void
    {
        $this->support->http->queueJson(503, [], ['Retry-After' => '2']);
        $this->support->http->queueJson(503, [], ['Retry-After' => '9']);
        $this->support->http->queueJson(200, []);

        $this->support->client()->sessions->retrieveDecision('abc');

        self::assertSame([2.0, 9.0], $this->support->sleeps, 'Retry-After may exceed the 8 s backoff cap');
    }

    public function testRetryAfterDoesNotMakePostRetryOn503(): void
    {
        $this->support->http->queueJson(503, [], ['Retry-After' => '1']);

        $this->expectException(InternalServerException::class);
        try {
            $this->support->client()->sessions->create(['workflow_id' => 'wf']);
        } finally {
            self::assertCount(1, $this->support->http->requests);
            self::assertSame([], $this->support->sleeps);
        }
    }

    public function testPolicyPredicates(): void
    {
        $requester = self::requester(0.5);

        foreach (['GET', 'HEAD', 'PUT', 'DELETE'] as $method) {
            foreach ([408, 429, 500, 502, 503] as $status) {
                self::assertTrue($requester->shouldRetryStatus($method, $status), "{$method} {$status}");
            }
            self::assertFalse($requester->shouldRetryStatus($method, 400), "{$method} 400");
            self::assertTrue($requester->shouldRetryConnection($method, new ApiConnectionException('x', true)));
            self::assertTrue($requester->shouldRetryConnection($method, new ApiTimeoutException('x', true)));
        }
        foreach (['POST', 'PATCH'] as $method) {
            self::assertTrue($requester->shouldRetryStatus($method, 429));
            foreach ([408, 500, 502, 503] as $status) {
                self::assertFalse($requester->shouldRetryStatus($method, $status), "{$method} {$status}");
            }
            self::assertFalse($requester->shouldRetryConnection($method, new ApiConnectionException('x', true)));
            self::assertFalse($requester->shouldRetryConnection($method, new ApiTimeoutException('x', true)));
            self::assertTrue($requester->shouldRetryConnection($method, new ApiConnectionException('x', false)));
        }
    }

    private static function requester(float $random): Requester
    {
        $factory = new HttpFactory();

        return new Requester(
            TestClient::options(),
            new HttpTransport(new MockHttpClient()),
            $factory,
            $factory,
            null,
            static function (float $seconds): void {
            },
            static fn (): float => $random,
            static fn (): float => 1_760_702_400.0,
        );
    }

    /**
     * @param class-string<ConnectException|RequestException> $class
     */
    private static function guzzleError(string $class, int $errno, string $error): \Throwable
    {
        $request = new Request('POST', 'https://verification.didit.me/v3/session/');
        $context = ['errno' => $errno, 'error' => $error];

        return ConnectException::class === $class
            ? new ConnectException("cURL error {$errno}: {$error}", $request, null, $context)
            : new RequestException("cURL error {$errno}: {$error}", $request, null, null, $context);
    }

    private static function networkError(string $message): NetworkExceptionInterface
    {
        return new class($message) extends \RuntimeException implements NetworkExceptionInterface {
            public function getRequest(): RequestInterface
            {
                return new Request('POST', 'https://verification.didit.me/v3/session/');
            }
        };
    }
}
