<?php

declare(strict_types=1);

namespace Didit\Tests\Unit;

use Didit\Core\BaseClient;
use Didit\Core\Requester;
use Didit\Core\StderrLogger;
use Didit\DiditClient;
use Didit\Exception\ApiConnectionException;
use Didit\Exception\NotFoundException;
use Didit\File;
use Didit\Tests\Support\MockHttpClient;
use Didit\Tests\Support\PhpProcess;
use Didit\Tests\Support\TestClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Debug logging: what is logged and, above all, what never is (DESIGN section 6).
 *
 * @internal
 */
final class LoggingTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $savedEnv = [];

    /** @var array{env: array<mixed>, server: array<mixed>} */
    private array $savedGlobals = ['env' => [], 'server' => []];

    private TestClient $support;

    /** @var \ArrayObject<int, array{level: mixed, message: string, context: array<mixed>}> */
    private \ArrayObject $records;

    protected function setUp(): void
    {
        $this->savedEnv['DIDIT_LOG'] = getenv('DIDIT_LOG');
        $this->savedGlobals = ['env' => $_ENV, 'server' => $_SERVER];
        self::unsetLogEnvironment();
        $this->support = new TestClient();
        $this->records = new \ArrayObject();
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $name => $value) {
            false === $value ? putenv($name) : putenv("{$name}={$value}");
        }
        $_ENV = $this->savedGlobals['env'];
        $_SERVER = $this->savedGlobals['server'];
    }

    public function testOneDebugLinePerRequest(): void
    {
        $this->support->http->queueHandler(function (RequestInterface $request): Response {
            $this->support->now += 0.125;

            return new Response(200, ['Content-Type' => 'application/json', 'X-Request-Id' => 'req_log_1'], '{"session_id":"abc"}');
        });

        $this->support->client(logger: $this->logger())->sessions->retrieveDecision('abc');

        self::assertSame([
            ['level' => LogLevel::DEBUG, 'message' => 'GET /v3/session/abc/decision/ 200 125ms request_id=req_log_1', 'context' => []],
        ], $this->records->getArrayCopy());
    }

    public function testOneLinePerAttemptWithTheRetryNumber(): void
    {
        $this->support->http->queueJson(503, ['detail' => 'busy'], ['X-Request-Id' => 'req_a']);
        $this->support->http->queueException(new ConnectException('cURL error 7: Failed to connect', new Request('GET', 'https://verification.didit.me/'), null, ['errno' => 7]));
        $this->support->http->queueJson(404, ['detail' => 'gone']);

        try {
            $this->support->client(logger: $this->logger())->sessions->retrieveDecision('abc');
            self::fail('no exception');
        } catch (NotFoundException) {
        }

        self::assertSame([
            'GET /v3/session/abc/decision/ 503 0ms request_id=req_a',
            'GET /v3/session/abc/decision/ connection-error 0ms retry=1',
            'GET /v3/session/abc/decision/ 404 0ms retry=2',
        ], $this->messages());
    }

    public function testConnectionErrorWithoutRetriesIsLogged(): void
    {
        $this->support->http->queueException(new ConnectException('cURL error 6: Could not resolve host', new Request('GET', 'https://verification.didit.me/'), null, ['errno' => 6]));

        try {
            $this->support->client(TestClient::options(maxRetries: 0), $this->logger())->sessions->retrieveDecision('abc');
            self::fail('no exception');
        } catch (ApiConnectionException) {
        }

        self::assertSame(['GET /v3/session/abc/decision/ connection-error 0ms'], $this->messages());
    }

    public function testNeverLogsTheApiKeyHeadersQueryValuesOrBodies(): void
    {
        $this->support->http->queueJson(200, ['count' => 1, 'next' => '/v3/sessions/?offset=1&vendor_data=secret-vendor', 'previous' => null, 'results' => [['session_id' => 'response-secret']]], ['X-Request-Id' => 'req_q', 'Set-Cookie' => 'session=cookie-secret']);
        $this->support->http->queueJson(200, ['count' => 1, 'next' => null, 'previous' => null, 'results' => []]);
        $this->support->http->queueJson(201, ['session_id' => 'created-secret'], ['X-Request-Id' => 'req_post']);
        $this->support->http->queueJson(200, ['status' => 'Approved']);
        $client = $this->support->client(TestClient::options(defaultHeaders: ['X-Trace' => 'header-secret']), $this->logger());

        iterator_to_array($client->sessions->list(['vendor_data' => 'query-secret', 'search' => 'Jane Doe']), false);
        $client->sessions->create(['workflow_id' => 'wf', 'vendor_data' => 'body-secret', 'contact_details' => ['email' => 'jane@example.com']]);
        $client->faceMatch->compare(['user_image' => File::fromBytes('image-secret', 'u.png'), 'ref_image' => File::fromBytes('r', 'r.png')]);

        self::assertSame([
            'GET /v3/sessions/ 200 0ms request_id=req_q',
            'GET /v3/sessions/ 200 0ms',
            'POST /v3/session/ 201 0ms request_id=req_post',
            'POST /v3/face-match/ 200 0ms',
        ], $this->messages());
        $everything = print_r($this->records->getArrayCopy(), true);
        foreach ([TestClient::API_KEY, 'x-api-key', 'User-Agent', 'X-Trace', 'header-secret', 'cookie-secret', 'query-secret', 'Jane', 'secret-vendor', 'vendor_data', 'body-secret', 'jane@example.com', 'image-secret', 'response-secret', 'created-secret', '?'] as $secret) {
            self::assertStringNotContainsString($secret, $everything);
        }
    }

    public function testAccountTokenIsNeverLogged(): void
    {
        $this->support->http->queueJson(200, []);

        $this->support->client(logger: $this->logger())->account->organizations->list();

        self::assertSame(['GET /organizations/me/ 200 0ms'], $this->messages(), 'the operation path, relative to the account base URL');
        self::assertStringNotContainsString(TestClient::ACCOUNT_TOKEN, print_r($this->records->getArrayCopy(), true));
    }

    public function testNoLoggingWithoutLoggerOrEnvironment(): void
    {
        $client = self::constructedClient();

        self::assertNull(self::clientLogger($client));
    }

    public function testDiditLogDebugInTheEnvironmentLogsToStderr(): void
    {
        putenv('DIDIT_LOG=debug');
        self::assertInstanceOf(StderrLogger::class, self::clientLogger(self::constructedClient()));

        putenv('DIDIT_LOG');
        $_ENV['DIDIT_LOG'] = 'DEBUG';
        self::assertInstanceOf(StderrLogger::class, self::clientLogger(self::constructedClient()), 'case-insensitive, from $_ENV');

        unset($_ENV['DIDIT_LOG']);
        $_SERVER['DIDIT_LOG'] = 'debug';
        self::assertInstanceOf(StderrLogger::class, self::clientLogger(self::constructedClient()), 'from $_SERVER');
    }

    public function testOtherDiditLogValuesDoNotLog(): void
    {
        foreach (['info', '1', 'true', ''] as $value) {
            putenv("DIDIT_LOG={$value}");
            self::assertNull(self::clientLogger(self::constructedClient()), "DIDIT_LOG={$value}");
        }
    }

    public function testExplicitLoggerWinsOverTheEnvironment(): void
    {
        putenv('DIDIT_LOG=debug');
        $logger = $this->logger();

        $client = self::constructedClient($logger);

        self::assertSame($logger, self::clientLogger($client));
    }

    public function testStderrLoggerFormat(): void
    {
        $output = PhpProcess::run(<<<'PHP'
            $logger = new Didit\Core\StderrLogger();
            $logger->debug('GET /v3/sessions/ 200 12ms request_id=req_1');
            $logger->warning(new class implements Stringable {
                public function __toString(): string
                {
                    return 'stringable message';
                }
            });
            echo 'stdout-untouched';
            PHP);

        self::assertSame('stdout-untouched', $output['stdout']);
        self::assertSame("[didit] debug GET /v3/sessions/ 200 12ms request_id=req_1\n[didit] warning stringable message\n", $output['stderr']);
    }

    public function testDiditLogDebugEndToEnd(): void
    {
        $code = <<<'PHP'
            $http = new Didit\Tests\Support\MockHttpClient();
            $http->queueJson(200, ['session_id' => 'abc'], ['X-Request-Id' => 'req_env']);
            $factory = new GuzzleHttp\Psr7\HttpFactory();
            $client = new Didit\DiditClient(apiKey: 'test_api_key_123', httpClient: $http, requestFactory: $factory, streamFactory: $factory);
            $client->sessions->retrieveDecision('abc');
            PHP;

        $output = PhpProcess::run($code, ['DIDIT_LOG' => 'debug']);
        self::assertSame('', $output['stdout']);
        self::assertMatchesRegularExpression('#^\[didit\] debug GET /v3/session/abc/decision/ 200 \d+ms request_id=req_env\n$#', $output['stderr']);
        self::assertStringNotContainsString('test_api_key_123', $output['stderr']);

        self::assertSame(['stdout' => '', 'stderr' => ''], PhpProcess::run($code), 'silent without DIDIT_LOG');
    }

    private function logger(): LoggerInterface
    {
        return new class($this->records) extends AbstractLogger {
            /**
             * @param \ArrayObject<int, array{level: mixed, message: string, context: array<mixed>}> $records
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
                $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
            }
        };
    }

    /**
     * @return list<string>
     */
    private function messages(): array
    {
        return array_values(array_map(static fn (array $record): string => $record['message'], $this->records->getArrayCopy()));
    }

    /**
     * @phpstan-impure reads the environment
     */
    private static function constructedClient(?LoggerInterface $logger = null): DiditClient
    {
        return new DiditClient(
            apiKey: TestClient::API_KEY,
            httpClient: new MockHttpClient(),
            requestFactory: new HttpFactory(),
            streamFactory: new HttpFactory(),
            logger: $logger,
        );
    }

    /**
     * @phpstan-impure
     */
    private static function clientLogger(DiditClient $client): mixed
    {
        $requester = (new \ReflectionProperty(BaseClient::class, 'requester'))->getValue($client);
        self::assertInstanceOf(Requester::class, $requester);

        return (new \ReflectionProperty(Requester::class, 'logger'))->getValue($requester);
    }

    private static function unsetLogEnvironment(): void
    {
        putenv('DIDIT_LOG');
        unset($_ENV['DIDIT_LOG'], $_SERVER['DIDIT_LOG']);
    }
}
