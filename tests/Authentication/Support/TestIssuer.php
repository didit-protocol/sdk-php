<?php

declare(strict_types=1);

namespace Didit\Tests\Authentication\Support;

use Didit\Authentication\Authentication;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Psr\SimpleCache\CacheInterface;

/**
 * A stubbed Didit issuer: local ES256 keys, a discovery document and a JWKS
 * served by {@see FakeHttpClient}, a settable clock and a token signer.
 */
final class TestIssuer
{
    public const ISSUER = 'https://acme.auth.didit.me';
    public const DISCOVERY_URL = self::ISSUER . '/.well-known/openid-configuration';
    public const JWKS_URL = self::ISSUER . '/.well-known/jwks.json';
    public const TOKEN_URL = self::ISSUER . '/token/';
    public const AUDIENCE = '7b0a6c2e-3f41-4c9a-8d55-1e2f3a4b5c6d';
    public const KID = 'k_test_primary';

    /** @var array<string, \OpenSSLAsymmetricKey> */
    private static array $keyPool = [];

    public FakeHttpClient $http;

    public int|float $now = 1792233674;

    /** @var list<string> Key IDs the JWKS publishes. */
    public array $published = [self::KID];

    /** @var array<string, mixed> Extra JWKS entries, served after the published keys. */
    public array $extraJwks = [];

    public ?string $jwksCacheControl = 'public, max-age=300';

    public ?string $discoveryCacheControl = 'public, max-age=300';

    /** @var array<string, mixed> Overrides merged into the discovery document. */
    public array $discoveryOverrides = [];

    public function __construct()
    {
        $this->http = new FakeHttpClient();
        $this->http->on('GET', self::DISCOVERY_URL, function (): Response {
            return self::json(200, $this->discoveryDocument(), null === $this->discoveryCacheControl ? [] : ['Cache-Control' => $this->discoveryCacheControl]);
        });
        $this->http->on('GET', self::JWKS_URL, function (): Response {
            return self::json(200, $this->jwks(), null === $this->jwksCacheControl ? [] : ['Cache-Control' => $this->jwksCacheControl]);
        });
    }

    /**
     * @return \Closure(): (int|float)
     */
    public function clock(): \Closure
    {
        return fn (): int|float => $this->now;
    }

    public function auth(?CacheInterface $cache = null): Authentication
    {
        return new Authentication(self::ISSUER, $this->http, new HttpFactory(), new HttpFactory(), $cache, 10.0, $this->clock());
    }

    /**
     * @return array<string, mixed>
     */
    public function discoveryDocument(): array
    {
        return $this->discoveryOverrides + [
            'issuer' => self::ISSUER,
            'authorization_endpoint' => self::ISSUER . '/sign-in',
            'token_endpoint' => self::TOKEN_URL,
            'jwks_uri' => self::JWKS_URL,
            'response_types_supported' => ['code'],
            'response_modes_supported' => ['query'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'subject_types_supported' => ['public'],
            'id_token_signing_alg_values_supported' => ['ES256'],
            'token_endpoint_auth_methods_supported' => ['none'],
            'code_challenge_methods_supported' => ['S256'],
            'scopes_supported' => ['openid', 'email', 'phone', 'profile', 'offline_access'],
            'claims_supported' => ['iss', 'aud', 'sub', 'sid', 'auth_time', 'nonce'],
        ];
    }

    /**
     * @return array{keys: list<mixed>}
     */
    public function jwks(): array
    {
        $keys = [];
        foreach ($this->published as $kid) {
            $keys[] = self::publicJwk($kid);
        }
        foreach ($this->extraJwks as $jwk) {
            $keys[] = $jwk;
        }

        return ['keys' => $keys];
    }

    /**
     * @return array{kty: string, crv: string, x: string, y: string, kid: string, use: string, alg: string}
     */
    public static function publicJwk(string $kid): array
    {
        $details = openssl_pkey_get_details(self::privateKey($kid));
        if (!\is_array($details) || !\is_array($details['ec'] ?? null)) {
            throw new \LogicException('Not an EC key.');
        }
        $x = $details['ec']['x'] ?? null;
        $y = $details['ec']['y'] ?? null;
        if (!\is_string($x) || !\is_string($y)) {
            throw new \LogicException('Missing EC coordinates.');
        }

        return [
            'kty' => 'EC',
            'crv' => 'P-256',
            'x' => self::b64(str_pad($x, 32, "\x00", \STR_PAD_LEFT)),
            'y' => self::b64(str_pad($y, 32, "\x00", \STR_PAD_LEFT)),
            'kid' => $kid,
            'use' => 'sig',
            'alg' => 'ES256',
        ];
    }

    public static function privateKey(string $kid): \OpenSSLAsymmetricKey
    {
        if (!isset(self::$keyPool[$kid])) {
            $key = openssl_pkey_new(['private_key_type' => \OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
            if (false === $key) {
                throw new \LogicException('Cannot generate an EC key: ' . (string) openssl_error_string());
            }
            self::$keyPool[$kid] = $key;
        }

        return self::$keyPool[$kid];
    }

    /**
     * Valid access token claims at the current time.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    public function accessClaims(array $overrides = []): array
    {
        $now = (int) $this->now;

        return array_merge([
            'iss' => self::ISSUER,
            'aud' => self::AUDIENCE,
            'sub' => '1c9d8b88-1bff-43d1-ad7a-88b2bc0024e9',
            'sid' => 'c9abf6cc-2d64-4bf2-b31e-a4d09a070ab1',
            'jti' => 'Rgqu7179e-lhJ0bvVeD-Yg',
            'iat' => $now,
            'exp' => $now + 300,
            'vendor_data' => 'user-42',
            'org' => ['id' => '3e9a1f0b-5c2d-4e7f-8a6b-9c0d1e2f3a4b', 'role' => 'admin'],
            'verified' => ['level' => 'full_id', 'over_18' => true, 'given_name' => 'Alex', 'country' => 'ES'],
            'amr' => ['password'],
            'act' => null,
            'client_id' => self::AUDIENCE,
        ], $overrides);
    }

    /**
     * Valid ID token claims at the current time.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    public function idClaims(array $overrides = []): array
    {
        $claims = $this->accessClaims([
            'jti' => 'MLtO4s8Hr-Brkp7v59OWFw',
            'auth_time' => (int) $this->now - 10,
            'email_verified' => true,
            'email' => 'person@example.com',
            'name' => 'Alex Example',
            'phone_number' => '+34600000000',
            'nonce' => 'n-0S6_WzA2Mj',
        ]);
        unset($claims['client_id']);

        return array_merge($claims, $overrides);
    }

    /**
     * An ES256 token over the given claims.
     *
     * @param array<string, mixed> $claims
     * @param array<string, mixed> $header merged over `{"alg": "ES256", "kid": ..., "typ": "at+jwt"}`
     */
    public function sign(array $claims, array $header = [], string $kid = self::KID): string
    {
        $header = array_merge(['alg' => 'ES256', 'kid' => $kid, 'typ' => 'at+jwt'], $header);
        $input = self::b64(self::encode($header)) . '.' . self::b64(self::encode($claims));
        if (!openssl_sign($input, $der, self::privateKey($kid), \OPENSSL_ALGO_SHA256) || !\is_string($der)) {
            throw new \LogicException('Signing failed.');
        }

        return $input . '.' . self::b64(self::derToRaw($der));
    }

    /**
     * Converts a DER ECDSA signature to the 64-byte JWS form.
     */
    public static function derToRaw(string $der): string
    {
        $offset = 2;
        $out = '';
        for ($i = 0; $i < 2; ++$i) {
            if ("\x02" !== $der[$offset]) {
                throw new \LogicException('Bad DER.');
            }
            $length = \ord($der[$offset + 1]);
            $integer = ltrim(substr($der, $offset + 2, $length), "\x00");
            $out .= str_pad($integer, 32, "\x00", \STR_PAD_LEFT);
            $offset += 2 + $length;
        }

        return $out;
    }

    public static function b64(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    public static function encode(mixed $value): string
    {
        return json_encode($value, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param array<string, string> $headers
     */
    public static function json(int $status, mixed $body, array $headers = []): Response
    {
        return new Response($status, $headers + ['Content-Type' => 'application/json'], self::encode($body));
    }
}
