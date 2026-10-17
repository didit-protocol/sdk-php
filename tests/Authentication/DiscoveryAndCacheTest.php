<?php

declare(strict_types=1);

namespace Didit\Tests\Authentication;

use Didit\Authentication\JsonWebKey;
use Didit\Authentication\TokenVerificationException;
use Didit\Exception\ApiConnectionException;
use Didit\Exception\ConfigurationException;
use Didit\Exception\DiditException;
use Didit\Exception\InternalServerException;
use Didit\Exception\NotFoundException;
use Didit\Tests\Authentication\Support\ArrayCache;
use Didit\Tests\Authentication\Support\TestIssuer;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;

final class DiscoveryAndCacheTest extends TestCase
{
    private TestIssuer $issuer;

    protected function setUp(): void
    {
        $this->issuer = new TestIssuer();
    }

    public function testFetchesAndParsesDiscoveryAndJwks(): void
    {
        $auth = $this->issuer->auth();

        $discovery = $auth->discovery();
        self::assertSame(TestIssuer::ISSUER, $discovery->issuer);
        self::assertSame(TestIssuer::TOKEN_URL, $discovery->tokenEndpoint);
        self::assertSame(TestIssuer::JWKS_URL, $discovery->jwksUri);
        self::assertSame(TestIssuer::ISSUER . '/sign-in', $discovery->authorizationEndpoint);
        self::assertSame(['S256'], $discovery->codeChallengeMethodsSupported);
        self::assertSame(['ES256'], $discovery->idTokenSigningAlgValuesSupported);
        self::assertNotNull($discovery->rawResponse());
        self::assertSame(200, $discovery->rawResponse()->status);

        $jwks = $auth->jwks();
        self::assertIsArray($jwks->keys);
        self::assertCount(1, $jwks->keys);
        self::assertInstanceOf(JsonWebKey::class, $jwks->keys[0]);
        self::assertSame(TestIssuer::KID, $jwks->keys[0]->kid);
        self::assertSame('EC', $jwks->keys[0]->kty);
        self::assertSame('P-256', $jwks->keys[0]->crv);

        $request = $this->issuer->http->requests[0];
        self::assertSame('GET', $request->getMethod());
        self::assertSame('application/json', $request->getHeaderLine('Accept'));
        self::assertStringStartsWith('didit-php/', $request->getHeaderLine('User-Agent'));
        foreach ($this->issuer->http->requests as $sent) {
            self::assertFalse($sent->hasHeader('x-api-key'));
            self::assertFalse($sent->hasHeader('Authorization'));
            self::assertFalse($sent->hasHeader('Origin'));
        }

        // Served from memory now, without the raw response.
        self::assertNull($auth->discovery()->rawResponse());
        self::assertSame(1, $this->issuer->http->count('GET', TestIssuer::DISCOVERY_URL));
        self::assertSame(1, $this->issuer->http->count('GET', TestIssuer::JWKS_URL));
    }

    public function testDiscoveryIssuerMismatchIsRefused(): void
    {
        foreach (['https://other.auth.didit.me', TestIssuer::ISSUER . '/', 'https://ACME.auth.didit.me', null] as $issuer) {
            $this->issuer->discoveryOverrides = ['issuer' => $issuer];
            try {
                $this->issuer->auth()->discovery();
                self::fail('Expected a ConfigurationException.');
            } catch (ConfigurationException $e) {
                self::assertStringContainsString('names the issuer', $e->getMessage());
            }
        }
    }

    public function testTokenVerificationFailsWhenDiscoveryNamesAnotherIssuer(): void
    {
        $this->issuer->discoveryOverrides = ['issuer' => 'https://evil.example'];

        $this->expectException(ConfigurationException::class);
        $this->issuer->auth()->verifyAccessToken($this->issuer->sign($this->issuer->accessClaims()));
    }

    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function foreignEndpoints(): iterable
    {
        yield 'jwks on another host' => ['jwks_uri', 'https://evil.example/.well-known/jwks.json'];
        yield 'jwks on a sibling issuer' => ['jwks_uri', 'https://other.auth.didit.me/.well-known/jwks.json'];
        yield 'jwks over http' => ['jwks_uri', 'http://acme.auth.didit.me/.well-known/jwks.json'];
        yield 'jwks on another port' => ['jwks_uri', 'https://acme.auth.didit.me:8443/.well-known/jwks.json'];
        yield 'jwks with credentials' => ['jwks_uri', 'https://user:pass@acme.auth.didit.me/.well-known/jwks.json'];
        yield 'jwks relative' => ['jwks_uri', '/.well-known/jwks.json'];
        yield 'jwks missing' => ['jwks_uri', null];
        yield 'token endpoint on another host' => ['token_endpoint', 'https://evil.example/token/'];
        yield 'token endpoint not a string' => ['token_endpoint', ['https://acme.auth.didit.me/token/']];
    }

    #[DataProvider('foreignEndpoints')]
    public function testEndpointsOutsideTheIssuerOriginAreRefused(string $field, mixed $url): void
    {
        $this->issuer->discoveryOverrides = [$field => $url];

        try {
            $this->issuer->auth()->discovery();
            self::fail('Expected a ConfigurationException.');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString($field, $e->getMessage());
        }
        self::assertSame(0, $this->issuer->http->count('GET', TestIssuer::JWKS_URL));
    }

    public function testDefaultPortAndHostCaseCountAsTheIssuerOrigin(): void
    {
        $this->issuer->discoveryOverrides = ['jwks_uri' => 'https://ACME.auth.didit.me:443/.well-known/jwks.json'];
        $this->issuer->http->on('GET', 'https://ACME.auth.didit.me:443/.well-known/jwks.json', TestIssuer::json(200, $this->issuer->jwks()));

        self::assertSame('user-42', $this->issuer->auth()->verifyAccessToken($this->issuer->sign($this->issuer->accessClaims()))->vendorData);
    }

    public function testCacheControlMaxAgeIsHonoured(): void
    {
        $this->issuer->jwksCacheControl = 'public, max-age=120';
        $auth = $this->issuer->auth();

        $auth->jwks();
        $this->issuer->now += 119;
        $auth->jwks();
        self::assertSame(1, $this->issuer->http->count('GET', TestIssuer::JWKS_URL));

        $this->issuer->now += 1;
        $auth->jwks();
        self::assertSame(2, $this->issuer->http->count('GET', TestIssuer::JWKS_URL));
        // The discovery document has its own lifetime (300 s).
        self::assertSame(1, $this->issuer->http->count('GET', TestIssuer::DISCOVERY_URL));
    }

    public function testFiveMinutesWhenThereIsNoCacheControl(): void
    {
        $this->issuer->discoveryCacheControl = null;
        $auth = $this->issuer->auth();

        $auth->discovery();
        $this->issuer->now += 299;
        $auth->discovery();
        self::assertSame(1, $this->issuer->http->count('GET', TestIssuer::DISCOVERY_URL));

        $this->issuer->now += 1;
        $auth->discovery();
        self::assertSame(2, $this->issuer->http->count('GET', TestIssuer::DISCOVERY_URL));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function uncacheable(): iterable
    {
        yield 'no-store' => ['no-store'];
        yield 'no-store with max-age' => ['max-age=300, no-store'];
        yield 'no-cache' => ['no-cache'];
        yield 'max-age zero' => ['public, max-age=0'];
        yield 'invalid max-age' => ['max-age=soon'];
    }

    #[DataProvider('uncacheable')]
    public function testUncacheableResponsesAreFetchedEveryTime(string $cacheControl): void
    {
        $this->issuer->jwksCacheControl = $cacheControl;
        $cache = new ArrayCache($this->issuer->clock());
        $auth = $this->issuer->auth($cache);
        $token = $this->issuer->sign($this->issuer->accessClaims());

        $auth->verifyAccessToken($token);
        $auth->verifyAccessToken($token);

        self::assertSame(2, $this->issuer->http->count('GET', TestIssuer::JWKS_URL));
        foreach (array_keys($cache->items) as $key) {
            self::assertStringEndsNotWith('.jwks', $key);
        }
    }

    public function testAgeIsSubtractedAndLongLifetimesAreCapped(): void
    {
        $this->issuer->http->replace('GET', TestIssuer::JWKS_URL, fn (): Response => TestIssuer::json(200, $this->issuer->jwks(), ['Cache-Control' => 'max-age=300', 'Age' => '250']));
        $auth = $this->issuer->auth();
        $auth->jwks();
        $this->issuer->now += 50;
        $auth->jwks();
        self::assertSame(2, $this->issuer->http->count('GET', TestIssuer::JWKS_URL));

        $this->issuer->discoveryCacheControl = 'max-age=31536000';
        $auth = $this->issuer->auth();
        $auth->discovery();
        $this->issuer->now += 3599;
        $auth->discovery();
        self::assertSame(2, $this->issuer->http->count('GET', TestIssuer::DISCOVERY_URL));
        $this->issuer->now += 1;
        $auth->discovery();
        self::assertSame(3, $this->issuer->http->count('GET', TestIssuer::DISCOVERY_URL));
    }

    public function testPsr16CacheIsSharedAcrossInstances(): void
    {
        $cache = new ArrayCache($this->issuer->clock());
        $token = $this->issuer->sign($this->issuer->accessClaims());

        $this->issuer->auth($cache)->verifyAccessToken($token);
        self::assertCount(2, $this->issuer->http->requests);

        // A new instance, as in the next PHP-FPM request: no requests at all.
        $this->issuer->now += 200;
        self::assertSame('user-42', $this->issuer->auth($cache)->verifyAccessToken($token)->vendorData);
        self::assertSame(TestIssuer::ISSUER, $this->issuer->auth($cache)->discovery()->issuer);
        self::assertCount(2, $this->issuer->http->requests);

        // The entries expire with the response's max-age.
        $this->issuer->now += 100;
        $this->issuer->auth($cache)->verifyAccessToken($this->issuer->sign($this->issuer->accessClaims()));
        self::assertCount(4, $this->issuer->http->requests);
    }

    public function testCacheKeysAreSafeForEveryPsr16Implementation(): void
    {
        $cache = new ArrayCache($this->issuer->clock());
        $auth = $this->issuer->auth($cache);
        $auth->verifyAccessToken($this->issuer->sign($this->issuer->accessClaims()));
        $this->issuer->now += 61;
        try {
            $auth->verifyAccessToken($this->issuer->sign($this->issuer->accessClaims(), [], 'k_never_published'));
        } catch (TokenVerificationException) {
        }

        self::assertNotSame([], $cache->keysUsed);
        foreach ($cache->keysUsed as $key) {
            self::assertMatchesRegularExpression('/^[A-Za-z0-9_.]{1,64}$/D', $key);
        }
    }

    public function testIssuersDoNotShareCacheEntries(): void
    {
        $cache = new ArrayCache($this->issuer->clock());
        $this->issuer->auth($cache)->discovery();

        $other = new TestIssuer();
        $other->http->replace('GET', TestIssuer::DISCOVERY_URL, TestIssuer::json(500, ['error' => 'unused']));
        $otherIssuer = 'https://other.auth.didit.me';
        $other->http->on('GET', $otherIssuer . '/.well-known/openid-configuration', TestIssuer::json(200, ['issuer' => $otherIssuer, 'token_endpoint' => $otherIssuer . '/token/', 'jwks_uri' => $otherIssuer . '/.well-known/jwks.json']));
        $auth = new \Didit\Authentication\Authentication($otherIssuer, $other->http, cache: $cache, clock: $this->issuer->clock());

        self::assertSame($otherIssuer, $auth->discovery()->issuer);
    }

    public function testRefetchLimitIsSharedThroughThePsr16Cache(): void
    {
        $cache = new ArrayCache($this->issuer->clock());
        $this->issuer->auth($cache)->verifyAccessToken($this->issuer->sign($this->issuer->accessClaims()));
        $unknown = $this->issuer->sign($this->issuer->accessClaims(), [], 'k_never_published');

        // Another process 61 s later refetches once...
        $this->issuer->now += 61;
        try {
            $this->issuer->auth($cache)->verifyAccessToken($unknown);
        } catch (TokenVerificationException) {
        }
        self::assertSame(2, $this->issuer->http->count('GET', TestIssuer::JWKS_URL));

        // ...and a third process within the minute does not.
        $this->issuer->now += 30;
        try {
            $this->issuer->auth($cache)->verifyAccessToken($unknown);
            self::fail('Expected unknown_key.');
        } catch (TokenVerificationException $e) {
            self::assertSame(TokenVerificationException::UNKNOWN_KEY, $e->reason);
        }
        self::assertSame(2, $this->issuer->http->count('GET', TestIssuer::JWKS_URL));
    }

    public function testCorruptCacheEntriesAreIgnored(): void
    {
        $cache = new ArrayCache($this->issuer->clock());
        $this->issuer->auth($cache)->jwks();
        foreach (array_keys($cache->items) as $key) {
            $cache->items[$key] = ['garbage', null];
        }

        self::assertSame('user-42', $this->issuer->auth($cache)->verifyAccessToken($this->issuer->sign($this->issuer->accessClaims()))->vendorData);
        self::assertSame(2, $this->issuer->http->count('GET', TestIssuer::JWKS_URL));
    }

    public function testCachedDiscoveryForAnotherIssuerIsNotTrusted(): void
    {
        $cache = new ArrayCache($this->issuer->clock());
        $this->issuer->auth($cache)->discovery();
        foreach ($cache->items as $key => [$value]) {
            if (\is_array($value) && \is_array($value['data'] ?? null) && isset($value['data']['issuer'])) {
                $value['data']['issuer'] = 'https://evil.example';
                $cache->items[$key] = [$value, null];
            }
        }

        self::assertSame(TestIssuer::ISSUER, $this->issuer->auth($cache)->discovery()->issuer);
        self::assertSame(2, $this->issuer->http->count('GET', TestIssuer::DISCOVERY_URL));
    }

    public function testABrokenCacheBackendFallsBackToFetching(): void
    {
        $cache = new ArrayCache($this->issuer->clock());
        $cache->broken = true;

        self::assertSame('user-42', $this->issuer->auth($cache)->verifyAccessToken($this->issuer->sign($this->issuer->accessClaims()))->vendorData);
    }

    public function testDiscoveryErrorsMapToStatusExceptions(): void
    {
        $this->issuer->http->replace('GET', TestIssuer::DISCOVERY_URL, TestIssuer::json(404, ['detail' => 'No Configuration matches the given query.'], ['X-Request-Id' => 'req-1']));
        try {
            $this->issuer->auth()->discovery();
            self::fail('Expected a NotFoundException.');
        } catch (NotFoundException $e) {
            self::assertSame(404, $e->status);
            self::assertSame('req-1', $e->requestId);
            self::assertSame('/.well-known/openid-configuration', $e->response->path);
            self::assertStringContainsString('No Configuration matches', $e->getMessage());
        }

        $this->issuer->http->replace('GET', TestIssuer::DISCOVERY_URL, TestIssuer::json(503, ['error' => 'temporarily_unavailable']));
        $this->expectException(InternalServerException::class);
        $this->issuer->auth()->discovery();
    }

    public function testRedirectsAreNotFollowed(): void
    {
        $this->issuer->http->replace('GET', TestIssuer::JWKS_URL, new Response(302, ['Location' => 'https://evil.example/jwks.json']));

        try {
            $this->issuer->auth()->jwks();
            self::fail('Expected an ApiStatusException.');
        } catch (\Didit\Exception\ApiStatusException $e) {
            self::assertSame(302, $e->status);
        }
        self::assertCount(2, $this->issuer->http->requests);
    }

    public function testNonJsonSuccessIsAnError(): void
    {
        $this->issuer->http->replace('GET', TestIssuer::DISCOVERY_URL, new Response(200, ['Content-Type' => 'text/html'], '<html>captive portal</html>'));

        $this->expectException(DiditException::class);
        $this->expectExceptionMessage('without a JSON object');
        $this->issuer->auth()->discovery();
    }

    public function testJwksWithoutKeysIsAnError(): void
    {
        $this->issuer->http->replace('GET', TestIssuer::JWKS_URL, TestIssuer::json(200, ['keys' => 'none']));

        $this->expectException(DiditException::class);
        $this->expectExceptionMessage('no "keys" array');
        $this->issuer->auth()->jwks();
    }

    public function testConnectionErrorsPropagate(): void
    {
        $this->issuer->http->replace('GET', TestIssuer::DISCOVERY_URL, static function (RequestInterface $request): Response {
            throw new class('Could not resolve host', $request) extends \RuntimeException implements NetworkExceptionInterface {
                public function __construct(string $message, private readonly RequestInterface $request)
                {
                    parent::__construct($message);
                }

                public function getRequest(): RequestInterface
                {
                    return $this->request;
                }
            };
        });

        $this->expectException(ApiConnectionException::class);
        $this->issuer->auth()->discovery();
    }
}
