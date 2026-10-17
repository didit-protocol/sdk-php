<?php

declare(strict_types=1);

namespace Didit\Tests\Authentication;

use Didit\Authentication\Authentication;
use Didit\Authentication\Base64Url;
use Didit\Authentication\CacheControl;
use Didit\Authentication\Pkce;
use Didit\Core\RawResponse;
use Didit\Exception\ConfigurationException;
use Didit\Tests\Authentication\Support\FakeHttpClient;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConfigurationAndPkceTest extends TestCase
{
    public function testPkceMatchesTheRfc7636AppendixBVector(): void
    {
        $pkce = Pkce::fromVerifier('dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk');

        self::assertSame('E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM', $pkce->challenge);
        self::assertSame('S256', $pkce->method);
        self::assertSame('dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk', $pkce->verifier);
    }

    public function testCreatePkceMakesAFreshS256Pair(): void
    {
        $pkce = Authentication::createPkce();

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/D', $pkce->verifier);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/D', $pkce->challenge);
        self::assertSame(Pkce::METHOD_S256, $pkce->method);
        self::assertSame(Base64Url::encode(hash('sha256', $pkce->verifier, true)), $pkce->challenge);
        self::assertNotSame($pkce->verifier, Authentication::createPkce()->verifier);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidVerifiers(): iterable
    {
        yield 'too short' => [str_repeat('a', 42)];
        yield 'too long' => [str_repeat('a', 129)];
        yield 'bad character' => [str_repeat('a', 42) . '+'];
        yield 'trailing newline' => [str_repeat('a', 43) . "\n"];
    }

    #[DataProvider('invalidVerifiers')]
    public function testInvalidVerifiersAreRefused(string $verifier): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Pkce::fromVerifier($verifier);
    }

    public function testVerifierBoundsAreAccepted(): void
    {
        self::assertSame(43, \strlen(Pkce::fromVerifier(str_repeat('~', 43))->verifier));
        self::assertSame(128, \strlen(Pkce::fromVerifier(str_repeat('a.-_', 32))->verifier));
    }

    public function testStateAndNonceAreRandomBase64Url(): void
    {
        $values = [];
        for ($i = 0; $i < 20; ++$i) {
            $values[] = Authentication::createState();
            $values[] = Authentication::createNonce();
        }
        foreach ($values as $value) {
            self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/D', $value);
        }
        self::assertCount(\count($values), array_unique($values));
    }

    public function testParsesLiveAndTestPublishableKeys(): void
    {
        $live = Authentication::fromPublishableKey('pk_live_' . Base64Url::encode('https://acme.auth.didit.me'));
        self::assertSame('https://acme.auth.didit.me', $live->issuer);

        $test = Authentication::fromPublishableKey('pk_test_' . Base64Url::encode('https://acme.sandbox.auth.didit.me'));
        self::assertSame('https://acme.sandbox.auth.didit.me', $test->issuer);

        $custom = Authentication::fromPublishableKey(' pk_live_' . Base64Url::encode('https://login.example.com') . "\n");
        self::assertSame('https://login.example.com', $custom->issuer);
    }

    public function testPublishableKeyClientUsesTheDecodedIssuer(): void
    {
        $http = new FakeHttpClient();
        $issuer = 'https://acme.sandbox.auth.didit.me';
        $http->on('GET', $issuer . '/.well-known/openid-configuration', new Response(200, [], (string) json_encode(['issuer' => $issuer, 'token_endpoint' => $issuer . '/token/', 'jwks_uri' => $issuer . '/.well-known/jwks.json'])));

        $auth = Authentication::fromPublishableKey('pk_test_' . Base64Url::encode($issuer), $http);

        self::assertSame($issuer, $auth->discovery()->issuer);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedPublishableKeys(): iterable
    {
        yield 'empty' => [''];
        yield 'secret key prefix' => ['sk_live_' . Base64Url::encode('https://acme.auth.didit.me')];
        yield 'upper-case prefix' => ['PK_LIVE_' . Base64Url::encode('https://acme.auth.didit.me')];
        yield 'unknown environment' => ['pk_prod_' . Base64Url::encode('https://acme.auth.didit.me')];
        yield 'prefix only' => ['pk_live_'];
        yield 'padded' => ['pk_live_' . base64_encode('https://acme.auth.didit.me/')];
        yield 'standard alphabet' => ['pk_live_' . str_replace(['-', '_'], ['+', '/'], Base64Url::encode('https://a.auth.didit.me/?>?'))];
        yield 'not base64' => ['pk_live_not*base64'];
        yield 'not a URL' => ['pk_live_' . Base64Url::encode('acme.auth.didit.me')];
        yield 'http issuer' => ['pk_live_' . Base64Url::encode('http://acme.auth.didit.me')];
        yield 'binary' => ['pk_test_' . Base64Url::encode("\x00\xff\x10")];
        yield 'issuer with query' => ['pk_test_' . Base64Url::encode('https://acme.auth.didit.me?x=1')];
    }

    #[DataProvider('malformedPublishableKeys')]
    public function testMalformedPublishableKeysAreRefused(string $key): void
    {
        $this->expectException(ConfigurationException::class);
        Authentication::fromPublishableKey($key);
    }

    public function testIssuerIsNormalisedAndValidated(): void
    {
        self::assertSame('https://acme.auth.didit.me', (new Authentication('https://acme.auth.didit.me/'))->issuer);
        self::assertSame('http://localhost:8000', (new Authentication('http://localhost:8000'))->issuer);
        self::assertSame('http://127.0.0.1:8000', (new Authentication('http://127.0.0.1:8000/'))->issuer);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidIssuers(): iterable
    {
        yield 'empty' => [''];
        yield 'host only' => ['acme.auth.didit.me'];
        yield 'http' => ['http://acme.auth.didit.me'];
        yield 'other scheme' => ['ftp://acme.auth.didit.me'];
        yield 'query' => ['https://acme.auth.didit.me/?tenant=1'];
        yield 'fragment' => ['https://acme.auth.didit.me/#x'];
        yield 'credentials' => ['https://user:pass@acme.auth.didit.me'];
        yield 'space' => ['https://acme.auth.didit.me/ x'];
        yield 'publishable key' => ['pk_live_' . Base64Url::encode('https://acme.auth.didit.me')];
    }

    #[DataProvider('invalidIssuers')]
    public function testInvalidIssuersAreRefused(string $issuer): void
    {
        $this->expectException(ConfigurationException::class);
        new Authentication($issuer);
    }

    public function testTimeoutMustBePositive(): void
    {
        $this->expectException(ConfigurationException::class);
        new Authentication('https://acme.auth.didit.me', timeout: 0.0);
    }

    /**
     * @return iterable<string, array{list<string>, array<string, string>, int}>
     */
    public static function cacheControlCases(): iterable
    {
        yield 'absent' => [[], [], 300];
        yield 'max-age' => [['public, max-age=120'], [], 120];
        yield 'quoted, upper case' => [['MAX-AGE="60"'], [], 60];
        yield 'split across headers' => [['public', 'max-age=90'], [], 90];
        yield 's-maxage ignored' => [['s-maxage=900'], [], 300];
        yield 'no-store' => [['no-store, max-age=300'], [], 0];
        yield 'no-cache' => [['max-age=300, no-cache'], [], 0];
        yield 'zero' => [['max-age=0'], [], 0];
        yield 'negative' => [['max-age=-5'], [], 0];
        yield 'garbage' => [['max-age=abc'], [], 0];
        yield 'age subtracted' => [['max-age=300'], ['Age' => '100'], 200];
        yield 'age beyond max-age' => [['max-age=300'], ['Age' => '400'], 0];
        yield 'invalid age ignored' => [['max-age=300'], ['Age' => 'old'], 300];
        yield 'capped' => [['max-age=86400'], [], 3600];
    }

    /**
     * @param list<string> $cacheControl
     * @param array<string, string> $headers
     */
    #[DataProvider('cacheControlCases')]
    public function testCacheControlTtl(array $cacheControl, array $headers, int $expected): void
    {
        $response = new Response(200, [] === $cacheControl ? $headers : $headers + ['Cache-Control' => $cacheControl]);

        self::assertSame($expected, CacheControl::ttl(new RawResponse($response)));
    }

    public function testStrictBase64UrlDecoding(): void
    {
        self::assertSame('', Base64Url::decode(''));
        self::assertSame("\xfb\xff", Base64Url::decode('-_8'));
        self::assertNull(Base64Url::decode('-_9'));
        self::assertNull(Base64Url::decode('+/8'));
        self::assertNull(Base64Url::decode('YQ=='));
        self::assertNull(Base64Url::decode('Y'));
        self::assertNull(Base64Url::decode("YQ\n"));
        self::assertSame('a', Base64Url::decode('YQ'));
        self::assertNull(Base64Url::decode('YR'));
    }
}
