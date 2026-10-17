<?php

declare(strict_types=1);

namespace Didit\Tests\Unit;

use Didit\Core\ClientOptions;
use Didit\DiditClient;
use Didit\Exception\ConfigurationException;
use Didit\RequestOptions;
use Didit\Tests\Support\MockHttpClient;
use GuzzleHttp\Psr7\HttpFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class ClientOptionsTest extends TestCase
{
    private const ENV = ['DIDIT_API_KEY', 'DIDIT_ORGANIZATION_ID', 'DIDIT_APPLICATION_ID', 'DIDIT_LOG'];

    /** @var array<string, string|false> */
    private array $saved = [];

    protected function setUp(): void
    {
        foreach (self::ENV as $name) {
            $this->saved[$name] = getenv($name);
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $name => $value) {
            false === $value ? putenv($name) : putenv("{$name}={$value}");
        }
    }

    public function testDefaults(): void
    {
        $options = ClientOptions::resolve();

        self::assertNull($options->apiKey);
        self::assertSame('https://verification.didit.me', $options->baseUrl);
        self::assertSame('https://apx.didit.me/auth/v2', $options->accountBaseUrl);
        self::assertSame(60.0, $options->timeout);
        self::assertSame(2, $options->maxRetries);
        self::assertNull($options->organizationId);
        self::assertNull($options->applicationId);
    }

    public function testEnvironmentFallbacks(): void
    {
        putenv('DIDIT_API_KEY=env_key');
        putenv('DIDIT_ORGANIZATION_ID=env_org');
        $_ENV['DIDIT_APPLICATION_ID'] = 'env_app';

        $options = ClientOptions::resolve();

        self::assertSame('env_key', $options->apiKey);
        self::assertSame('env_org', $options->organizationId);
        self::assertSame('env_app', $options->applicationId);
    }

    public function testExplicitValuesWinOverEnvironment(): void
    {
        putenv('DIDIT_API_KEY=env_key');

        self::assertSame('explicit', ClientOptions::resolve(apiKey: 'explicit')->apiKey);
        self::assertSame('env_key', ClientOptions::resolve(apiKey: '')->apiKey);
    }

    public function testBaseUrlTrailingSlashIsRemoved(): void
    {
        self::assertSame('http://localhost:8080/didit', ClientOptions::resolve(baseUrl: 'http://localhost:8080/didit/')->baseUrl);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidBaseUrls(): iterable
    {
        yield 'relative' => ['/v3'];
        yield 'ftp' => ['ftp://example.com'];
        yield 'credentials' => ['https://user:pass@example.com'];
        yield 'query' => ['https://example.com?x=1'];
    }

    #[DataProvider('invalidBaseUrls')]
    public function testInvalidBaseUrlIsAConfigurationError(string $url): void
    {
        $this->expectException(ConfigurationException::class);
        ClientOptions::resolve(baseUrl: $url);
    }

    public function testTimeoutAndRetriesAreValidated(): void
    {
        try {
            ClientOptions::resolve(timeout: 0.0);
            self::fail('timeout 0 accepted');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString('timeout', $e->getMessage());
        }
        $this->expectException(ConfigurationException::class);
        ClientOptions::resolve(maxRetries: -1);
    }

    public function testMergeAppliesOnlyGivenValues(): void
    {
        $options = ClientOptions::resolve(apiKey: 'key', organizationId: 'org', defaultHeaders: ['X-A' => '1']);
        $merged = $options->merge(new RequestOptions(timeout: 5.0, headers: ['X-B' => '2'], applicationId: 'app'));

        self::assertSame('key', $merged->apiKey);
        self::assertSame('org', $merged->organizationId);
        self::assertSame('app', $merged->applicationId);
        self::assertSame(5.0, $merged->timeout);
        self::assertSame(2, $merged->maxRetries);
        self::assertSame(['X-A' => '1', 'X-B' => '2'], $merged->defaultHeaders);
        self::assertSame($options, $options->merge(null));
    }

    public function testSecretsAreRedactedFromDebugOutput(): void
    {
        $options = ClientOptions::resolve(apiKey: 'sk_secret_value', accountToken: 'tok_secret_value');
        $client = new DiditClient(apiKey: 'sk_secret_value', httpClient: new MockHttpClient(), requestFactory: new HttpFactory(), streamFactory: new HttpFactory());
        $requestOptions = new RequestOptions(apiKey: 'sk_secret_value');

        foreach ([$options, $client, $requestOptions] as $object) {
            $dump = print_r($object, true);
            self::assertStringNotContainsString('sk_secret_value', $dump);
            self::assertStringNotContainsString('tok_secret_value', $dump);
            self::assertStringContainsString('[redacted]', $dump);
        }
    }

    public function testConstructingAClientNeedsNoApiKey(): void
    {
        $client = new DiditClient(httpClient: new MockHttpClient(), requestFactory: new HttpFactory(), streamFactory: new HttpFactory());

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('DIDIT_API_KEY');
        $client->sessions->list();
    }

    public function testWithOptionsReturnsAnIndependentCopy(): void
    {
        $http = new MockHttpClient();
        $http->queueJson(200, ['count' => 0, 'next' => null, 'previous' => null, 'results' => []]);
        $client = new DiditClient(apiKey: 'first', httpClient: $http, requestFactory: new HttpFactory(), streamFactory: new HttpFactory());

        $copy = $client->withOptions(apiKey: 'second', timeout: 3.0);
        $copy->sessions->list();

        self::assertNotSame($client, $copy);
        self::assertSame('second', $http->lastRequest()->getHeaderLine('x-api-key'));
        $info = $client->__debugInfo();
        self::assertIsArray($info['options']);
        self::assertSame(60.0, $info['options']['timeout']);
    }
}
