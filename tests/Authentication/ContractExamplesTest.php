<?php

declare(strict_types=1);

namespace Didit\Tests\Authentication;

use Didit\Authentication\Authentication;
use Didit\Authentication\Base64Url;
use Didit\Authentication\TokenVerificationException;
use Didit\Tests\Authentication\Support\FakeHttpClient;
use Didit\Tests\Authentication\Support\TestIssuer;
use PHPUnit\Framework\TestCase;

/**
 * The example tokens and signing key of the Authentication API contract
 * (mintlify-docs `openapi-authentication.json`, `POST /token/` and
 * `GET /.well-known/jwks.json` examples) verify with this SDK: a signer
 * other than the test's own.
 */
final class ContractExamplesTest extends TestCase
{
    private const ISSUER = 'https://your-app.auth.didit.me';
    private const APPLICATION_ID = '7b0a6c2e-3f41-4c9a-8d55-1e2f3a4b5c6d';
    private const ISSUED_AT = 1792233674;
    /**
     * The example tokens as their decoded header and payload JSON plus the
     * signature, joined into compact JWTs at test time. The bytes are exactly
     * those of the contract's examples, so the signatures verify.
     */
    private const EXAMPLES = [
        'access' => [
            '{"alg":"ES256","kid":"k_8sJ2dQvX0nLr5TfYb3HkPw","typ":"at+jwt"}',
            '{"iss":"https://your-app.auth.didit.me","aud":"7b0a6c2e-3f41-4c9a-8d55-1e2f3a4b5c6d","sub":"1c9d8b88-1bff-43d1-ad7a-88b2bc0024e9","sid":"c9abf6cc-2d64-4bf2-b31e-a4d09a070ab1","jti":"Rgqu7179e-lhJ0bvVeD-Yg","iat":1792233674,"exp":1792233974,"vendor_data":"user-42","org":null,"verified":{},"amr":["password"],"act":null,"client_id":"7b0a6c2e-3f41-4c9a-8d55-1e2f3a4b5c6d"}',
            'AxrqN8Pm-Op-3OayZk8HcvlFQZhozRiOvQ0SgQsGgrz4UNeJlUEeSlq9B8WcYUuM8naRaY5kQt_TUofnsYcyYQ',
        ],
        'id' => [
            '{"alg":"ES256","kid":"k_8sJ2dQvX0nLr5TfYb3HkPw","typ":"JWT"}',
            '{"iss":"https://your-app.auth.didit.me","aud":"7b0a6c2e-3f41-4c9a-8d55-1e2f3a4b5c6d","sub":"1c9d8b88-1bff-43d1-ad7a-88b2bc0024e9","sid":"c9abf6cc-2d64-4bf2-b31e-a4d09a070ab1","jti":"MLtO4s8Hr-Brkp7v59OWFw","iat":1792233674,"exp":1792233974,"vendor_data":"user-42","org":null,"verified":{},"amr":["password"],"act":null,"auth_time":1792233674,"email_verified":true,"email":"person@example.com","nonce":"n-0S6_WzA2Mj"}',
            'avhfDFp_aUV5_1ssYzwDcIA7H6Waf19P0bn5GCg25FhLIZBGIlwc8HiUPGj1j26r21OkP4FQnS_jM1Ho3dHtKQ',
        ],
    ];
    private const NONCE = 'n-0S6_WzA2Mj';
    private const JWK = [
        'kty' => 'EC',
        'crv' => 'P-256',
        'x' => 'ZDN2unLMultuZwNY_ZKMvsKLRrlC_usPFZyK77cnDO8',
        'y' => 'CfLKoaJNvhFpDWqG56H8dD_j0f1c93RdR63bhIdTh3I',
        'kid' => 'k_8sJ2dQvX0nLr5TfYb3HkPw',
        'use' => 'sig',
        'alg' => 'ES256',
    ];

    private int $now = self::ISSUED_AT + 30;

    public function testTheExampleAccessTokenVerifies(): void
    {
        $claims = $this->auth()->verifyAccessToken(self::example('access'), self::APPLICATION_ID);

        self::assertSame(self::ISSUER, $claims->iss);
        self::assertSame(self::APPLICATION_ID, $claims->clientId);
        self::assertSame('1c9d8b88-1bff-43d1-ad7a-88b2bc0024e9', $claims->sub);
        self::assertSame('user-42', $claims->vendorData);
        self::assertSame(['password'], $claims->amr);
        self::assertNull($claims->org);
        self::assertNotNull($claims->verified);
        self::assertNull($claims->verified->level);
    }

    public function testTheExampleIdTokenVerifies(): void
    {
        $claims = $this->auth()->verifyIdToken(self::example('id'), self::NONCE, self::APPLICATION_ID);

        self::assertSame('person@example.com', $claims->email);
        self::assertTrue($claims->emailVerified);
        self::assertSame(self::ISSUED_AT, $claims->authTime);
    }

    public function testTheExampleTokensAreNotInterchangeable(): void
    {
        $auth = $this->auth();

        foreach ([static fn () => $auth->verifyAccessToken(self::example('id')), static fn () => $auth->verifyIdToken(self::example('access'), null, self::APPLICATION_ID)] as $call) {
            try {
                $call();
                self::fail('Expected invalid_type.');
            } catch (TokenVerificationException $e) {
                self::assertSame(TokenVerificationException::INVALID_TYPE, $e->reason);
            }
        }
    }

    public function testTheExampleAccessTokenExpiresAfterFiveMinutesAndTheLeeway(): void
    {
        $this->now = self::ISSUED_AT + 300 + 60;

        $this->expectException(TokenVerificationException::class);
        $this->auth()->verifyAccessToken(self::example('access'));
    }

    /**
     * @param 'access'|'id' $kind
     */
    private static function example(string $kind): string
    {
        [$header, $payload, $signature] = self::EXAMPLES[$kind];

        return Base64Url::encode($header) . '.' . Base64Url::encode($payload) . '.' . $signature;
    }

    private function auth(): Authentication
    {
        $http = new FakeHttpClient();
        $http->on('GET', self::ISSUER . '/.well-known/openid-configuration', TestIssuer::json(200, [
            'issuer' => self::ISSUER,
            'token_endpoint' => self::ISSUER . '/token/',
            'jwks_uri' => self::ISSUER . '/.well-known/jwks.json',
        ]));
        $http->on('GET', self::ISSUER . '/.well-known/jwks.json', TestIssuer::json(200, ['keys' => [self::JWK]]));

        return new Authentication(self::ISSUER, $http, clock: fn (): int => $this->now);
    }
}
