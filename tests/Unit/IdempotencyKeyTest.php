<?php

declare(strict_types=1);

namespace Didit\Tests\Unit;

use Didit\Core\ClientOptions;
use Didit\Core\HttpTransport;
use Didit\Core\Operation;
use Didit\Core\Requester;
use Didit\DiditClient;
use Didit\Exception\ConfigurationException;
use Didit\Exception\ConflictException;
use Didit\Exception\InternalServerException;
use Didit\RequestOptions;
use Didit\Tests\Support\GuzzleErrors;
use Didit\Tests\Support\TestClient;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * The per-request idempotency key (DESIGN sections 6 and 7).
 *
 * @internal
 */
final class IdempotencyKeyTest extends TestCase
{
    private const KEY = 'order-1042';

    private TestClient $support;

    protected function setUp(): void
    {
        $this->support = new TestClient();
    }

    /**
     * @return iterable<string, array{string, \Closure(DiditClient, RequestOptions): mixed}>
     */
    public static function writeCalls(): iterable
    {
        yield 'POST' => ['POST', static fn (DiditClient $c, RequestOptions $o): mixed => $c->sessions->create(['workflow_id' => 'wf'], $o)];
        yield 'PUT' => ['PUT', static fn (DiditClient $c, RequestOptions $o): mixed => $c->travelRule->updateSettings(['legal_name' => 'Example VASP'], $o)];
        yield 'PATCH' => ['PATCH', static fn (DiditClient $c, RequestOptions $o): mixed => $c->sessions->updateStatus('abc', ['new_status' => 'Approved'], $o)];
        yield 'DELETE' => ['DELETE', static fn (DiditClient $c, RequestOptions $o): mixed => $c->workflows->delete('wf-1', $o)];
    }

    /**
     * @param \Closure(DiditClient, RequestOptions): mixed $call
     */
    #[DataProvider('writeCalls')]
    public function testTheHeaderIsSentOnWrites(string $method, \Closure $call): void
    {
        $this->support->http->queueJson(200, []);

        $call($this->support->client(), new RequestOptions(idempotencyKey: self::KEY));

        $request = $this->support->http->lastRequest();
        self::assertSame($method, $request->getMethod());
        self::assertSame([self::KEY], $request->getHeader('Idempotency-Key'));
    }

    public function testTheHeaderIsLeftOutOfGet(): void
    {
        $this->support->http->queueJson(200, []);

        $this->support->client()->sessions->retrieveDecision('abc', [], new RequestOptions(idempotencyKey: self::KEY));

        self::assertSame('GET', $this->support->http->lastRequest()->getMethod());
        self::assertFalse($this->support->http->lastRequest()->hasHeader('Idempotency-Key'));
    }

    public function testTheHeaderIsLeftOutOfHead(): void
    {
        $this->support->http->queueJson(200, []);

        $this->requester()->request(new Operation(method: 'HEAD', path: '/v3/ping/'), [], [], new RequestOptions(idempotencyKey: self::KEY));

        self::assertSame('HEAD', $this->support->http->lastRequest()->getMethod());
        self::assertFalse($this->support->http->lastRequest()->hasHeader('Idempotency-Key'));
    }

    public function testWithoutTheOptionNoHeaderIsSentAndNothingIsGenerated(): void
    {
        $this->support->http->queueJson(200, []);

        $this->support->client()->sessions->create(['workflow_id' => 'wf']);

        self::assertFalse($this->support->http->lastRequest()->hasHeader('Idempotency-Key'));
    }

    public function testTheClientDoesNotAcceptAnIdempotencyKey(): void
    {
        foreach ([DiditClient::class, ClientOptions::class] as $class) {
            $constructor = (new \ReflectionClass($class))->getConstructor();
            self::assertNotNull($constructor);
            foreach ($constructor->getParameters() as $parameter) {
                self::assertStringNotContainsStringIgnoringCase('idempotency', $parameter->getName(), $class);
            }
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidKeys(): iterable
    {
        yield 'empty' => [''];
        yield '256 characters' => [str_repeat('k', 256)];
        yield '256 multibyte characters' => [str_repeat("\u{e9}", 256)];
    }

    #[DataProvider('invalidKeys')]
    public function testAnInvalidKeyIsRejectedBeforeAnyRequest(string $key): void
    {
        $this->support->http->queueJson(200, []);

        try {
            $this->support->client()->sessions->create(['workflow_id' => 'wf'], new RequestOptions(idempotencyKey: $key));
            self::fail('no exception');
        } catch (ConfigurationException) {
        }
        self::assertCount(0, $this->support->http->requests);
    }

    public function testKeysOfOneAnd255CharactersAreAccepted(): void
    {
        $this->support->http->queueJson(200, []);
        $this->support->http->queueJson(200, []);
        $client = $this->support->client();

        $client->sessions->create(['workflow_id' => 'wf'], new RequestOptions(idempotencyKey: 'k'));
        $client->sessions->create(['workflow_id' => 'wf'], new RequestOptions(idempotencyKey: str_repeat('k', 255)));

        self::assertCount(2, $this->support->http->requests);
    }

    /**
     * @return iterable<string, array{string, \Closure(DiditClient, RequestOptions): mixed}>
     */
    public static function postAndPatchCalls(): iterable
    {
        foreach (self::writeCalls() as $name => $case) {
            if (\in_array($name, ['POST', 'PATCH'], true)) {
                yield $name => $case;
            }
        }
    }

    /**
     * @param \Closure(DiditClient, RequestOptions): mixed $call
     */
    #[DataProvider('postAndPatchCalls')]
    public function testAKeyedWriteIsRetriedOnA5xxWithTheSameKey(string $method, \Closure $call): void
    {
        $this->support->http->queueJson(503, ['detail' => 'down']);
        $this->support->http->queueJson(500, ['detail' => 'down']);
        $this->support->http->queueJson(200, []);

        $call($this->support->client(), new RequestOptions(idempotencyKey: self::KEY));

        self::assertCount(3, $this->support->http->requests);
        foreach ($this->support->http->requests as $request) {
            self::assertSame($method, $request->getMethod());
            self::assertSame([self::KEY], $request->getHeader('Idempotency-Key'));
        }
        self::assertSame([0.25, 0.5], $this->support->sleeps);
    }

    /**
     * @param \Closure(DiditClient, RequestOptions): mixed $call
     */
    #[DataProvider('postAndPatchCalls')]
    public function testAKeyedWriteIsRetriedOnAReadTimeoutAndOn408(string $method, \Closure $call): void
    {
        $request = new Request($method, 'https://verification.didit.me/v3/session/');
        $this->support->http->queueException(GuzzleErrors::connect('cURL error 28: Operation timed out after 60001 milliseconds with 0 bytes received', $request, 28, 'Operation timed out after 60001 milliseconds with 0 bytes received'));
        $this->support->http->queueJson(408, ['detail' => 'timeout']);
        $this->support->http->queueJson(200, []);

        $call($this->support->client(), new RequestOptions(idempotencyKey: self::KEY));

        self::assertCount(3, $this->support->http->requests);
        foreach ($this->support->http->requests as $attempt) {
            self::assertSame([self::KEY], $attempt->getHeader('Idempotency-Key'));
        }
    }

    /**
     * @param \Closure(DiditClient, RequestOptions): mixed $call
     */
    #[DataProvider('postAndPatchCalls')]
    public function testAKeyedWriteStopsAfterMaxRetries(string $method, \Closure $call): void
    {
        for ($i = 0; $i < 3; ++$i) {
            $this->support->http->queueJson(503, ['detail' => 'down']);
        }

        try {
            $call($this->support->client(), new RequestOptions(idempotencyKey: self::KEY));
            self::fail('no exception');
        } catch (InternalServerException) {
        }
        self::assertCount(3, $this->support->http->requests);
    }

    public function testAWriteWithoutAKeyIsStillNotRetriedOnA5xxOrAReadTimeout(): void
    {
        $this->support->http->queueJson(503, ['detail' => 'down']);
        try {
            $this->support->client()->sessions->create(['workflow_id' => 'wf'], new RequestOptions(timeout: 5.0));
            self::fail('no exception');
        } catch (InternalServerException) {
        }
        self::assertCount(1, $this->support->http->requests);
        self::assertSame([], $this->support->sleeps);
    }

    public function testRequesterPredicatesFollowTheKey(): void
    {
        $requester = $this->requester();

        foreach (['POST', 'PATCH'] as $method) {
            foreach ([408, 429, 500, 503] as $status) {
                self::assertTrue($requester->shouldRetryStatus($method, $status, true), "{$method} {$status} with a key");
            }
            self::assertFalse($requester->shouldRetryStatus($method, 500));
            self::assertFalse($requester->shouldRetryStatus($method, 400, true));
            self::assertTrue($requester->shouldRetryConnection($method, new \Didit\Exception\ApiTimeoutException('x', true), true));
            self::assertFalse($requester->shouldRetryConnection($method, new \Didit\Exception\ApiTimeoutException('x', true)));
        }
    }

    public function testAnInProgressConflictIsRetriedHonouringRetryAfter(): void
    {
        $this->support->http->queueJson(409, ['detail' => 'still running', 'code' => 'idempotency_key_in_progress'], ['Retry-After' => '1']);
        $this->support->http->queueJson(201, ['session_id' => 's']);

        $this->support->client()->sessions->create(['workflow_id' => 'wf'], new RequestOptions(idempotencyKey: self::KEY));

        self::assertCount(2, $this->support->http->requests);
        self::assertSame([1.0], $this->support->sleeps);
        self::assertSame([self::KEY], $this->support->http->requests[1]->getHeader('Idempotency-Key'));
    }

    public function testAnInProgressConflictWithoutAKeyIsNotRetried(): void
    {
        $this->support->http->queueJson(409, ['detail' => 'still running', 'code' => 'idempotency_key_in_progress'], ['Retry-After' => '1']);

        $this->expectException(ConflictException::class);
        try {
            $this->support->client()->sessions->create(['workflow_id' => 'wf']);
        } finally {
            self::assertCount(1, $this->support->http->requests);
        }
    }

    public function testAReusedKeyConflictIsRaisedAtOnce(): void
    {
        $this->support->http->queueJson(409, ['detail' => 'reused', 'code' => 'idempotency_key_reused']);
        $this->support->http->queueJson(201, ['session_id' => 's']);

        $this->expectException(ConflictException::class);
        try {
            $this->support->client()->sessions->create(['workflow_id' => 'wf'], new RequestOptions(idempotencyKey: self::KEY));
        } finally {
            self::assertCount(1, $this->support->http->requests);
            self::assertSame([], $this->support->sleeps);
        }
    }

    public function testAnotherConflictWithAKeyIsRaisedAtOnce(): void
    {
        $this->support->http->queueJson(409, ['detail' => 'conflict']);
        $this->support->http->queueJson(201, ['session_id' => 's']);

        $this->expectException(ConflictException::class);
        try {
            $this->support->client()->sessions->create(['workflow_id' => 'wf'], new RequestOptions(idempotencyKey: self::KEY));
        } finally {
            self::assertCount(1, $this->support->http->requests);
        }
    }

    public function testTheOptionWinsOverAGeneratedHeaderParameter(): void
    {
        $this->support->http->queueJson(200, []);

        $this->support->client()->aml->screen(
            ['entity_type' => 'person', 'full_name' => 'Jane Doe', 'idempotency_key' => 'from-parameter'],
            new RequestOptions(idempotencyKey: 'from-option'),
        );

        self::assertSame(['from-option'], $this->support->http->lastRequest()->getHeader('Idempotency-Key'));
    }

    public function testAGeneratedHeaderParameterAloneMakesAPostRetryable(): void
    {
        $this->support->http->queueJson(503, ['detail' => 'down']);
        $this->support->http->queueJson(200, []);

        $this->support->client()->aml->screen(['entity_type' => 'person', 'full_name' => 'Jane Doe', 'idempotency_key' => 'from-parameter']);

        self::assertCount(2, $this->support->http->requests);
        foreach ($this->support->http->requests as $request) {
            self::assertSame(['from-parameter'], $request->getHeader('Idempotency-Key'));
        }
    }

    public function testAMethodOutsideTheAllowListNeverCarriesTheKey(): void
    {
        $this->support->http->queueJson(200, []);

        $this->requester()->request(new Operation(method: 'OPTIONS', path: '/v3/ping/'), [], [], new RequestOptions(idempotencyKey: self::KEY, headers: ['idempotency-key' => 'raw']));

        self::assertSame('OPTIONS', $this->support->http->lastRequest()->getMethod());
        self::assertFalse($this->support->http->lastRequest()->hasHeader('Idempotency-Key'));
    }

    public function testAClientWideDefaultHeaderIsIgnoredAndDoesNotMakeAPostRetryable(): void
    {
        $this->support->http->queueJson(503, ['detail' => 'down']);
        $client = $this->support->client(TestClient::options(defaultHeaders: ['idempotency-key' => 'client-wide']));

        try {
            $client->sessions->create(['workflow_id' => 'wf']);
            self::fail('no exception');
        } catch (InternalServerException) {
        }
        self::assertCount(1, $this->support->http->requests);
        self::assertFalse($this->support->http->lastRequest()->hasHeader('Idempotency-Key'));

        $this->support->http->queueJson(200, []);
        $client->sessions->create(['workflow_id' => 'wf'], new RequestOptions(idempotencyKey: self::KEY));
        self::assertSame([self::KEY], $this->support->http->lastRequest()->getHeader('Idempotency-Key'));
    }

    public function testAPerRequestRawHeaderCountsAsAKeyWhateverItsCase(): void
    {
        $this->support->http->queueJson(503, ['detail' => 'down']);
        $this->support->http->queueJson(200, []);

        $this->support->client()->sessions->create(['workflow_id' => 'wf'], new RequestOptions(headers: ['idempotency-key' => self::KEY]));

        self::assertCount(2, $this->support->http->requests);
        foreach ($this->support->http->requests as $request) {
            self::assertSame([self::KEY], $request->getHeader('Idempotency-Key'));
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidKeysForParameterAndRawHeader(): iterable
    {
        yield 'empty' => [''];
        yield '256 characters' => [str_repeat('k', 256)];
    }

    #[DataProvider('invalidKeysForParameterAndRawHeader')]
    public function testAnInvalidGeneratedParameterIsRejectedBeforeAnyRequest(string $key): void
    {
        $this->support->http->queueJson(200, []);

        try {
            $this->support->client()->aml->screen(['entity_type' => 'person', 'full_name' => 'Jane Doe', 'idempotency_key' => $key]);
            self::fail('no exception');
        } catch (ConfigurationException) {
        }
        self::assertCount(0, $this->support->http->requests);
    }

    #[DataProvider('invalidKeysForParameterAndRawHeader')]
    public function testAnInvalidRawHeaderIsRejectedBeforeAnyRequest(string $key): void
    {
        $this->support->http->queueJson(200, []);

        try {
            $this->support->client()->sessions->create(['workflow_id' => 'wf'], new RequestOptions(headers: ['Idempotency-Key' => $key]));
            self::fail('no exception');
        } catch (ConfigurationException) {
        }
        self::assertCount(0, $this->support->http->requests);
    }

    public function testOptionRawHeaderAndParameterTogetherSendOneHeaderWithTheOptionValue(): void
    {
        $this->support->http->queueJson(200, []);

        $this->support->client(TestClient::options(defaultHeaders: ['IDEMPOTENCY-KEY' => 'client-wide']))->aml->screen(
            ['entity_type' => 'person', 'full_name' => 'Jane Doe', 'idempotency_key' => 'from-parameter'],
            new RequestOptions(idempotencyKey: 'from-option', headers: ['idempotency-key' => 'from-raw']),
        );

        $request = $this->support->http->lastRequest();
        self::assertSame(['from-option'], $request->getHeader('Idempotency-Key'));
        self::assertCount(1, array_filter(array_keys($request->getHeaders()), static fn ($name): bool => 'idempotency-key' === strtolower((string) $name)));
    }

    public function testARawHeaderBeatsTheGeneratedParameter(): void
    {
        $this->support->http->queueJson(200, []);

        $this->support->client()->aml->screen(
            ['entity_type' => 'person', 'full_name' => 'Jane Doe', 'idempotency_key' => 'from-parameter'],
            new RequestOptions(headers: ['idempotency-key' => 'from-raw']),
        );

        self::assertSame(['from-raw'], $this->support->http->lastRequest()->getHeader('Idempotency-Key'));
    }

    public function testAConflictWithANonJsonBodyIsRaisedAtOnce(): void
    {
        $this->support->http->queue(409, 'idempotency_key_in_progress', ['Retry-After' => '1']);
        $this->support->http->queueJson(201, ['session_id' => 's']);

        $this->expectException(ConflictException::class);
        try {
            $this->support->client()->sessions->create(['workflow_id' => 'wf'], new RequestOptions(idempotencyKey: self::KEY));
        } finally {
            self::assertCount(1, $this->support->http->requests);
            self::assertSame([], $this->support->sleeps);
        }
    }

    public function testTheKeyNeverAppearsInDebugLogs(): void
    {
        /** @var \ArrayObject<int, string> $records */
        $records = new \ArrayObject();
        $logger = new class($records) extends AbstractLogger {
            /**
             * @param \ArrayObject<int, string> $records
             */
            public function __construct(private readonly \ArrayObject $records)
            {
            }

            /**
             * @param string|\Stringable $message
             * @param array<mixed> $context
             */
            public function log($level, $message, array $context = []): void
            {
                $this->records[] = (string) $message . json_encode($context);
            }
        };
        $this->support->http->queueJson(503, ['detail' => 'down']);
        $this->support->http->queueJson(201, ['session_id' => 's']);

        $this->support->client(logger: $logger)->sessions->create(['workflow_id' => 'wf'], new RequestOptions(idempotencyKey: 'order-123'));

        self::assertCount(2, $records);
        foreach ($records as $message) {
            self::assertStringNotContainsString('order-123', $message);
            self::assertStringNotContainsStringIgnoringCase('idempotency', $message);
        }
    }

    private function requester(): Requester
    {
        $factory = new HttpFactory();

        return new Requester(
            TestClient::options(),
            new HttpTransport($this->support->http),
            $factory,
            $factory,
            null,
            function (float $seconds): void {
                $this->support->sleeps[] = $seconds;
            },
            static fn (): float => 0.5,
            static fn (): float => 1_760_702_400.0,
        );
    }
}
