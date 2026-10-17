<?php

declare(strict_types=1);

namespace Didit\Tests\Authentication;

use Didit\Authentication\ActorClaim;
use Didit\Authentication\OrganizationClaim;
use Didit\Authentication\TokenVerificationException;
use Didit\Authentication\VerifiedClaims;
use Didit\Tests\Authentication\Support\TestIssuer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TokenVerificationTest extends TestCase
{
    private TestIssuer $issuer;

    protected function setUp(): void
    {
        $this->issuer = new TestIssuer();
    }

    public function testVerifiesAValidAccessToken(): void
    {
        $token = $this->issuer->sign($this->issuer->accessClaims(['extra_claim' => 'kept']));

        $claims = $this->issuer->auth()->verifyAccessToken($token, TestIssuer::AUDIENCE);

        self::assertSame(TestIssuer::ISSUER, $claims->iss);
        self::assertSame(TestIssuer::AUDIENCE, $claims->aud);
        self::assertSame(TestIssuer::AUDIENCE, $claims->clientId);
        self::assertSame('1c9d8b88-1bff-43d1-ad7a-88b2bc0024e9', $claims->sub);
        self::assertSame('c9abf6cc-2d64-4bf2-b31e-a4d09a070ab1', $claims->sid);
        self::assertSame('Rgqu7179e-lhJ0bvVeD-Yg', $claims->jti);
        self::assertSame(1792233674, $claims->iat);
        self::assertSame(1792233974, $claims->exp);
        self::assertSame('user-42', $claims->vendorData);
        self::assertSame(['password'], $claims->amr);
        self::assertInstanceOf(OrganizationClaim::class, $claims->org);
        self::assertSame('3e9a1f0b-5c2d-4e7f-8a6b-9c0d1e2f3a4b', $claims->org->id);
        self::assertSame(OrganizationClaim::ROLE_ADMIN, $claims->org->role);
        self::assertInstanceOf(VerifiedClaims::class, $claims->verified);
        self::assertSame(VerifiedClaims::LEVEL_FULL_ID, $claims->verified->level);
        self::assertTrue($claims->verified->over18);
        self::assertSame('Alex', $claims->verified->givenName);
        self::assertNull($claims->act);
        self::assertSame('kept', $claims['extra_claim']);
        self::assertSame('kept', $claims->toArray()['extra_claim']);
    }

    public function testVerifiesWithoutAudienceAndReadsAgentClaims(): void
    {
        $token = $this->issuer->sign($this->issuer->accessClaims([
            'org' => null,
            'verified' => [],
            'amr' => [],
            'act' => ['sub' => 'agent-1', 'agent' => 'Helper', 'scopes' => ['read', 'pay']],
        ]));

        $claims = $this->issuer->auth()->verifyAccessToken($token);

        self::assertNull($claims->org);
        self::assertInstanceOf(VerifiedClaims::class, $claims->verified);
        self::assertNull($claims->verified->level);
        self::assertSame([], $claims->amr);
        self::assertInstanceOf(ActorClaim::class, $claims->act);
        self::assertSame('agent-1', $claims->act->sub);
        self::assertSame('Helper', $claims->act->agent);
        self::assertSame(['read', 'pay'], $claims->act->scopes);
    }

    public function testAcceptsAnAudienceArrayContainingTheAudience(): void
    {
        $token = $this->issuer->sign($this->issuer->accessClaims(['aud' => ['other', TestIssuer::AUDIENCE]]));

        $claims = $this->issuer->auth()->verifyAccessToken($token, TestIssuer::AUDIENCE);

        self::assertNull($claims->aud);
        self::assertSame(['other', TestIssuer::AUDIENCE], $claims['aud']);
    }

    public function testAcceptsTheFullMediaTypeForTyp(): void
    {
        $token = $this->issuer->sign($this->issuer->accessClaims(), ['typ' => 'application/AT+JWT']);

        self::assertSame('user-42', $this->issuer->auth()->verifyAccessToken($token)->vendorData);
    }

    public function testVerifiesAValidIdToken(): void
    {
        $token = $this->issuer->sign($this->issuer->idClaims(), ['typ' => 'JWT']);

        $claims = $this->issuer->auth()->verifyIdToken($token, 'test-nonce-7f3a', TestIssuer::AUDIENCE);

        self::assertSame(TestIssuer::AUDIENCE, $claims->aud);
        self::assertSame('test-nonce-7f3a', $claims->nonce);
        self::assertSame(1792233664, $claims->authTime);
        self::assertSame('person@example.com', $claims->email);
        self::assertTrue($claims->emailVerified);
        self::assertSame('Alex Example', $claims->name);
        self::assertSame('+34600000000', $claims->phoneNumber);
        self::assertSame('1c9d8b88-1bff-43d1-ad7a-88b2bc0024e9', $claims->sub);
        self::assertFalse($claims->has('client_id'));
    }

    public function testIdTokenFromARefreshVerifiesWithANullNonce(): void
    {
        $claims = $this->issuer->idClaims();
        unset($claims['nonce']);
        $token = $this->issuer->sign($claims, ['typ' => 'JWT']);

        self::assertNull($this->issuer->auth()->verifyIdToken($token, null, TestIssuer::AUDIENCE)->nonce);
    }

    public function testNullNonceRejectsATokenThatCarriesOne(): void
    {
        $token = $this->issuer->sign($this->issuer->idClaims(), ['typ' => 'JWT']);

        $this->assertRejected(TokenVerificationException::INVALID_NONCE, fn () => $this->issuer->auth()->verifyIdToken($token, null, TestIssuer::AUDIENCE));
    }

    public function testWrongNonceIsRejected(): void
    {
        $token = $this->issuer->sign($this->issuer->idClaims(), ['typ' => 'JWT']);

        $this->assertRejected(TokenVerificationException::INVALID_NONCE, fn () => $this->issuer->auth()->verifyIdToken($token, 'another', TestIssuer::AUDIENCE));
    }

    public function testMissingNonceIsRejectedWhenOneIsExpected(): void
    {
        $claims = $this->issuer->idClaims();
        unset($claims['nonce']);
        $token = $this->issuer->sign($claims, ['typ' => 'JWT']);

        $this->assertRejected(TokenVerificationException::INVALID_NONCE, fn () => $this->issuer->auth()->verifyIdToken($token, 'test-nonce-7f3a', TestIssuer::AUDIENCE));
    }

    public function testIdTokenAudienceIsRequiredToMatch(): void
    {
        $token = $this->issuer->sign($this->issuer->idClaims(), ['typ' => 'JWT']);

        $this->assertRejected(TokenVerificationException::INVALID_AUDIENCE, fn () => $this->issuer->auth()->verifyIdToken($token, 'test-nonce-7f3a', 'another-app'));
    }

    public function testAnIdTokenIsNotAnAccessToken(): void
    {
        $token = $this->issuer->sign($this->issuer->idClaims(), ['typ' => 'JWT']);

        $this->assertRejected(TokenVerificationException::INVALID_TYPE, fn () => $this->issuer->auth()->verifyAccessToken($token));
    }

    public function testAnAccessTokenIsNotAnIdToken(): void
    {
        $token = $this->issuer->sign($this->issuer->accessClaims(['nonce' => 'n']));

        $this->assertRejected(TokenVerificationException::INVALID_TYPE, fn () => $this->issuer->auth()->verifyIdToken($token, 'n', TestIssuer::AUDIENCE));
    }

    public function testMissingTypIsRejected(): void
    {
        $token = $this->issuer->sign($this->issuer->accessClaims(), ['typ' => null]);

        $this->assertRejected(TokenVerificationException::INVALID_TYPE, fn () => $this->issuer->auth()->verifyAccessToken($token));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function rejectedAlgorithms(): iterable
    {
        foreach (['none', 'None', 'HS256', 'HS384', 'HS512', 'RS256', 'RS512', 'PS256', 'ES384', 'ES512', 'EdDSA', 'es256', 'ES256K', ''] as $alg) {
            yield $alg => [$alg];
        }
        yield 'missing' => [null];
        yield 'array' => [['ES256']];
    }

    #[DataProvider('rejectedAlgorithms')]
    public function testRejectsEveryAlgorithmButEs256BeforeTouchingKeys(mixed $alg): void
    {
        $header = TestIssuer::b64(TestIssuer::encode(['alg' => $alg, 'kid' => TestIssuer::KID, 'typ' => 'at+jwt']));
        $payload = TestIssuer::b64(TestIssuer::encode($this->issuer->accessClaims()));
        $token = $header . '.' . $payload . '.' . TestIssuer::b64(hash_hmac('sha256', $header . '.' . $payload, 'secret', true));

        $this->assertRejected(TokenVerificationException::UNSUPPORTED_ALGORITHM, fn () => $this->issuer->auth()->verifyAccessToken($token));
        self::assertSame([], $this->issuer->http->requests);
    }

    public function testRejectsAlgNoneWithAnEmptySignature(): void
    {
        $token = TestIssuer::b64(TestIssuer::encode(['alg' => 'none', 'typ' => 'at+jwt'])) . '.' . TestIssuer::b64(TestIssuer::encode($this->issuer->accessClaims())) . '.';

        $this->assertRejected(TokenVerificationException::UNSUPPORTED_ALGORITHM, fn () => $this->issuer->auth()->verifyAccessToken($token));
        self::assertSame([], $this->issuer->http->requests);
    }

    public function testRejectsARealRs256Token(): void
    {
        $rsa = openssl_pkey_new(['private_key_type' => \OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        self::assertNotFalse($rsa);
        $input = TestIssuer::b64(TestIssuer::encode(['alg' => 'RS256', 'kid' => TestIssuer::KID, 'typ' => 'at+jwt'])) . '.' . TestIssuer::b64(TestIssuer::encode($this->issuer->accessClaims()));
        self::assertTrue(openssl_sign($input, $signature, $rsa, \OPENSSL_ALGO_SHA256));
        self::assertIsString($signature);

        $this->assertRejected(TokenVerificationException::UNSUPPORTED_ALGORITHM, fn () => $this->issuer->auth()->verifyAccessToken($input . '.' . TestIssuer::b64($signature)));
        self::assertSame([], $this->issuer->http->requests);
    }

    public function testWrongIssuerIsRejected(): void
    {
        foreach (['https://evil.auth.didit.me', TestIssuer::ISSUER . '/', 'HTTPS://acme.auth.didit.me', null, 42] as $iss) {
            $token = $this->issuer->sign($this->issuer->accessClaims(['iss' => $iss]));
            $this->assertRejected(TokenVerificationException::INVALID_ISSUER, fn () => $this->issuer->auth()->verifyAccessToken($token));
        }
    }

    public function testWrongAudienceIsRejected(): void
    {
        foreach (['another-app', ['another-app'], null, 7, [7]] as $aud) {
            $token = $this->issuer->sign($this->issuer->accessClaims(['aud' => $aud]));
            $this->assertRejected(TokenVerificationException::INVALID_AUDIENCE, fn () => $this->issuer->auth()->verifyAccessToken($token, TestIssuer::AUDIENCE));
        }
    }

    public function testExpiredTokenIsRejected(): void
    {
        $token = $this->issuer->sign($this->issuer->accessClaims(['iat' => $this->issuer->now - 1000, 'exp' => $this->issuer->now - 600]));

        $this->assertRejected(TokenVerificationException::EXPIRED, fn () => $this->issuer->auth()->verifyAccessToken($token));
    }

    public function testExpiryLeewayBoundary(): void
    {
        $now = (int) $this->issuer->now;
        $auth = $this->issuer->auth();

        $valid = $this->issuer->sign($this->issuer->accessClaims(['iat' => $now - 400, 'exp' => $now - 59]));
        self::assertSame($now - 59, $auth->verifyAccessToken($valid)->exp);

        $expired = $this->issuer->sign($this->issuer->accessClaims(['iat' => $now - 400, 'exp' => $now - 60]));
        $this->assertRejected(TokenVerificationException::EXPIRED, static fn () => $auth->verifyAccessToken($expired));
    }

    public function testNotBeforeInTheFutureIsRejected(): void
    {
        $now = (int) $this->issuer->now;
        $auth = $this->issuer->auth();

        $token = $this->issuer->sign($this->issuer->accessClaims(['nbf' => $now + 61]));
        $this->assertRejected(TokenVerificationException::NOT_YET_VALID, static fn () => $auth->verifyAccessToken($token));

        $withinLeeway = $this->issuer->sign($this->issuer->accessClaims(['nbf' => $now + 60]));
        self::assertSame($now + 60, $auth->verifyAccessToken($withinLeeway)['nbf']);
    }

    public function testIssuedInTheFutureBeyondLeewayIsRejected(): void
    {
        $now = (int) $this->issuer->now;
        $auth = $this->issuer->auth();

        $token = $this->issuer->sign($this->issuer->accessClaims(['iat' => $now + 61]));
        $this->assertRejected(TokenVerificationException::ISSUED_IN_FUTURE, static fn () => $auth->verifyAccessToken($token));

        $withinLeeway = $this->issuer->sign($this->issuer->accessClaims(['iat' => $now + 60]));
        self::assertSame($now + 60, $auth->verifyAccessToken($withinLeeway)->iat);
    }

    public function testTimeClaimsMustBePresentAndNumeric(): void
    {
        $auth = $this->issuer->auth();
        foreach (['exp', 'iat'] as $name) {
            $claims = $this->issuer->accessClaims();
            unset($claims[$name]);
            $token = $this->issuer->sign($claims);
            $this->assertRejected(TokenVerificationException::INVALID_CLAIM, static fn () => $auth->verifyAccessToken($token));
        }
        foreach (['exp', 'iat', 'nbf'] as $name) {
            foreach (['1792233974', true, null, [1]] as $value) {
                $token = $this->issuer->sign($this->issuer->accessClaims([$name => $value]));
                $this->assertRejected(TokenVerificationException::INVALID_CLAIM, static fn () => $auth->verifyAccessToken($token));
            }
        }
    }

    public function testFractionalTimesAreAccepted(): void
    {
        $now = (int) $this->issuer->now;
        $token = $this->issuer->sign($this->issuer->accessClaims(['iat' => $now - 0.5, 'exp' => $now + 299.5]));

        $claims = $this->issuer->auth()->verifyAccessToken($token);

        self::assertNull($claims->exp);
        self::assertSame($now + 299.5, $claims['exp']);
    }

    public function testTamperedPayloadFailsTheSignature(): void
    {
        $token = $this->issuer->sign($this->issuer->accessClaims());
        [$header, , $signature] = explode('.', $token);
        $forged = $header . '.' . TestIssuer::b64(TestIssuer::encode($this->issuer->accessClaims(['sub' => 'someone-else']))) . '.' . $signature;

        $this->assertRejected(TokenVerificationException::INVALID_SIGNATURE, fn () => $this->issuer->auth()->verifyAccessToken($forged));
    }

    public function testSignatureFromAnotherKeyWithTheSameKidIsRejected(): void
    {
        $impostor = $this->issuer->sign($this->issuer->accessClaims(), ['kid' => TestIssuer::KID], 'k_impostor');

        $this->assertRejected(TokenVerificationException::INVALID_SIGNATURE, fn () => $this->issuer->auth()->verifyAccessToken($impostor));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badSignatures(): iterable
    {
        yield 'too short' => [TestIssuer::b64(str_repeat("\x01", 63))];
        yield 'too long' => [TestIssuer::b64(str_repeat("\x01", 65))];
        yield 'DER instead of raw' => [TestIssuer::b64("\x30\x44\x02\x20" . str_repeat("\x01", 32) . "\x02\x20" . str_repeat("\x01", 32))];
        yield 'zero r' => [TestIssuer::b64(str_repeat("\x00", 32) . str_repeat("\x01", 32))];
        yield 'zero s' => [TestIssuer::b64(str_repeat("\x01", 32) . str_repeat("\x00", 32))];
        yield 'all ones' => [TestIssuer::b64(str_repeat("\xff", 64))];
    }

    #[DataProvider('badSignatures')]
    public function testBadSignatureIsRejected(string $signature): void
    {
        [$header, $payload] = explode('.', $this->issuer->sign($this->issuer->accessClaims()));

        $this->assertRejected(TokenVerificationException::INVALID_SIGNATURE, fn () => $this->issuer->auth()->verifyAccessToken($header . '.' . $payload . '.' . $signature));
    }

    /**
     * @return iterable<string, array{\Closure(string): string}>
     */
    public static function malformedTokens(): iterable
    {
        yield 'empty' => [static fn (string $t): string => ''];
        yield 'one segment' => [static fn (string $t): string => explode('.', $t)[0]];
        yield 'two segments' => [static fn (string $t): string => implode('.', \array_slice(explode('.', $t), 0, 2))];
        yield 'four segments' => [static fn (string $t): string => $t . '.abc'];
        yield 'JWE shaped' => [static fn (string $t): string => $t . '.abc.def'];
        yield 'padded signature' => [static fn (string $t): string => $t . '='];
        yield 'standard base64 signature' => [static fn (string $t): string => substr($t, 0, -2) . '+/'];
        yield 'empty signature' => [static fn (string $t): string => substr($t, 0, (int) strrpos($t, '.') + 1)];
        yield 'space in header' => [static fn (string $t): string => ' ' . $t];
        yield 'impossible length' => [static fn (string $t): string => 'A.' . explode('.', $t)[1] . '.' . explode('.', $t)[2]];
        yield 'non-canonical trailing bits' => [static fn (string $t): string => self::replaceSegment($t, 0, self::nonCanonical('{"alg":"ES256","kid":"k_test_primary","typ":"at+jwt"}'))];
        yield 'header not JSON' => [static fn (string $t): string => self::replaceSegment($t, 0, TestIssuer::b64('not json'))];
        yield 'header JSON list' => [static fn (string $t): string => self::replaceSegment($t, 0, TestIssuer::b64('["ES256"]'))];
        yield 'header JSON string' => [static fn (string $t): string => self::replaceSegment($t, 0, TestIssuer::b64('"ES256"'))];
        yield 'payload not JSON' => [static fn (string $t): string => self::replaceSegment($t, 1, TestIssuer::b64('{"sub":'))];
        yield 'payload JSON list' => [static fn (string $t): string => self::replaceSegment($t, 1, TestIssuer::b64('[]'))];
        yield 'payload invalid UTF-8' => [static fn (string $t): string => self::replaceSegment($t, 1, TestIssuer::b64("{\"sub\":\"\xff\"}"))];
        yield 'oversized' => [static fn (string $t): string => self::replaceSegment($t, 1, str_repeat('A', 20000))];
    }

    #[DataProvider('malformedTokens')]
    public function testMalformedTokenIsRejected(\Closure $mutate): void
    {
        $token = $mutate($this->issuer->sign($this->issuer->accessClaims()));
        self::assertIsString($token);

        $this->assertRejected(TokenVerificationException::MALFORMED, fn () => $this->issuer->auth()->verifyAccessToken($token));
    }

    public function testCriticalHeaderIsRejected(): void
    {
        $token = $this->issuer->sign($this->issuer->accessClaims(), ['crit' => ['exp']]);

        $this->assertRejected(TokenVerificationException::MALFORMED, fn () => $this->issuer->auth()->verifyAccessToken($token));
    }

    public function testMissingKidIsAnUnknownKey(): void
    {
        $token = $this->issuer->sign($this->issuer->accessClaims(), ['kid' => null]);

        $this->assertRejected(TokenVerificationException::UNKNOWN_KEY, fn () => $this->issuer->auth()->verifyAccessToken($token));
    }

    public function testUnknownKidRefetchesTheJwksOnceAndFindsARotatedKey(): void
    {
        $auth = $this->issuer->auth();
        $auth->verifyAccessToken($this->issuer->sign($this->issuer->accessClaims()));
        self::assertSame(1, $this->issuer->http->count('GET', TestIssuer::JWKS_URL));

        // The issuer publishes a new key; the cached JWKS does not have it yet.
        $this->issuer->now += 61;
        $this->issuer->published = [TestIssuer::KID, 'k_test_next'];
        $token = $this->issuer->sign($this->issuer->accessClaims(), [], 'k_test_next');

        self::assertSame('user-42', $auth->verifyAccessToken($token)->vendorData);
        self::assertSame(2, $this->issuer->http->count('GET', TestIssuer::JWKS_URL));

        // Both keys are now cached: no more requests.
        $auth->verifyAccessToken($token);
        $auth->verifyAccessToken($this->issuer->sign($this->issuer->accessClaims()));
        self::assertSame(2, $this->issuer->http->count('GET', TestIssuer::JWKS_URL));
    }

    public function testUnknownKidRefetchesAtMostOncePerMinute(): void
    {
        $auth = $this->issuer->auth();
        $auth->verifyAccessToken($this->issuer->sign($this->issuer->accessClaims()));
        $unknown = $this->issuer->sign($this->issuer->accessClaims(), [], 'k_never_published');

        // Fetched less than a minute ago: no refetch.
        $this->issuer->now += 30;
        $this->assertRejected(TokenVerificationException::UNKNOWN_KEY, static fn () => $auth->verifyAccessToken($unknown));
        self::assertSame(1, $this->issuer->http->count('GET', TestIssuer::JWKS_URL));

        // A minute after the last fetch: exactly one refetch.
        $this->issuer->now += 30;
        $this->assertRejected(TokenVerificationException::UNKNOWN_KEY, static fn () => $auth->verifyAccessToken($unknown));
        self::assertSame(2, $this->issuer->http->count('GET', TestIssuer::JWKS_URL));

        // Many unknown kids in the next minute: still no refetch.
        for ($i = 0; $i < 5; ++$i) {
            $this->issuer->now += 10;
            $this->assertRejected(TokenVerificationException::UNKNOWN_KEY, static fn () => $auth->verifyAccessToken($unknown));
        }
        self::assertSame(2, $this->issuer->http->count('GET', TestIssuer::JWKS_URL));

        $this->issuer->now += 10;
        $this->assertRejected(TokenVerificationException::UNKNOWN_KEY, static fn () => $auth->verifyAccessToken($unknown));
        self::assertSame(3, $this->issuer->http->count('GET', TestIssuer::JWKS_URL));
    }

    public function testUnknownKidOnAFreshFetchDoesNotFetchTwice(): void
    {
        $unknown = $this->issuer->sign($this->issuer->accessClaims(), [], 'k_never_published');

        $this->assertRejected(TokenVerificationException::UNKNOWN_KEY, fn () => $this->issuer->auth()->verifyAccessToken($unknown));

        self::assertSame(1, $this->issuer->http->count('GET', TestIssuer::JWKS_URL));
    }

    public function testOnlyUsableEcP256KeysMatchAKid(): void
    {
        $good = TestIssuer::publicJwk(TestIssuer::KID);
        $this->issuer->published = [];
        $this->issuer->extraJwks = [
            ['kty' => 'RSA', 'kid' => TestIssuer::KID, 'n' => 'AQAB', 'e' => 'AQAB'],
            ['alg' => 'RS256'] + $good,
            ['use' => 'enc'] + $good,
            ['crv' => 'P-384'] + $good,
            ['x' => TestIssuer::b64(str_repeat("\x01", 32))] + $good,
            ['x' => TestIssuer::b64(str_repeat("\x01", 31))] + $good,
            ['x' => $good['x'] . '='] + $good,
            'not a key',
        ];
        $token = $this->issuer->sign($this->issuer->accessClaims());

        $this->assertRejected(TokenVerificationException::UNKNOWN_KEY, fn () => $this->issuer->auth()->verifyAccessToken($token));

        $withoutOptional = $good;
        unset($withoutOptional['alg'], $withoutOptional['use']);
        $this->issuer->extraJwks[] = $withoutOptional;
        self::assertSame('user-42', $this->issuer->auth()->verifyAccessToken($token)->vendorData);
    }

    public function testExceptionMessageNeverContainsTheToken(): void
    {
        $token = $this->issuer->sign($this->issuer->accessClaims(['exp' => $this->issuer->now - 600, 'iat' => $this->issuer->now - 900]));
        try {
            $this->issuer->auth()->verifyAccessToken($token);
            self::fail('Expected an exception.');
        } catch (TokenVerificationException $e) {
            self::assertStringNotContainsString(explode('.', $token)[1], $e->getMessage());
            self::assertStringNotContainsString(explode('.', $token)[2], $e->getMessage());
        }
    }

    private static function replaceSegment(string $token, int $index, string $segment): string
    {
        $parts = explode('.', $token);
        $parts[$index] = $segment;

        return implode('.', $parts);
    }

    /**
     * The base64url of the JSON with stray bits set in its last character,
     * which a strict decoder must refuse.
     */
    private static function nonCanonical(string $json): string
    {
        while (1 !== \strlen($json) % 3) {
            $json .= ' ';
        }
        $encoded = TestIssuer::b64($json);
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';
        $last = (int) strpos($alphabet, $encoded[\strlen($encoded) - 1]);

        return substr($encoded, 0, -1) . $alphabet[$last | 0x0F];
    }

    /**
     * @param \Closure(): mixed $call
     */
    private function assertRejected(string $reason, \Closure $call): void
    {
        try {
            $call();
        } catch (TokenVerificationException $e) {
            self::assertSame($reason, $e->reason, $e->getMessage());

            return;
        }
        self::fail(\sprintf('Expected a TokenVerificationException with reason "%s".', $reason));
    }
}
