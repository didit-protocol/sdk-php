<?php

declare(strict_types=1);

namespace Didit\Tests\Authentication;

use Didit\Authentication\StepUp;
use Didit\Authentication\VerifiedClaims;
use Didit\Authentication\WalletVerification;
use Didit\Exception\ApiStatusException;
use Didit\Exception\AuthenticationException;
use Didit\Exception\BadRequestException;
use Didit\Exception\InternalServerException;
use Didit\Exception\NotFoundException;
use Didit\Exception\PermissionDeniedException;
use Didit\Exception\RateLimitException;
use Didit\Tests\Authentication\Support\TestIssuer;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class RuntimeEndpointsTest extends TestCase
{
    private const SESSION_URL = TestIssuer::ISSUER . '/session/';
    private const STEP_UP_URL = TestIssuer::ISSUER . '/step-up/';
    private const STEP_UP_ID = '94efc2c3-ca84-42fe-900b-6fb61669a7a1';
    private const ACCESS_TOKEN = 'test-access-token';

    private TestIssuer $issuer;

    protected function setUp(): void
    {
        $this->issuer = new TestIssuer();
    }

    public function testExchangeCodeSendsTheAuthorizationCodeGrantFormEncoded(): void
    {
        $this->issuer->http->on('POST', TestIssuer::TOKEN_URL, TestIssuer::json(200, self::tokenResponse(), ['Cache-Control' => 'no-store']));

        $tokens = $this->issuer->auth()->exchangeCode('Vb0bVt4CLlr8ZL0kE5mAxv8gS6R0rW3hYqC1pN7dJ2s', 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk', 'https://app.example.com/auth/callback?x=1&y=2');

        $request = $this->issuer->http->last();
        self::assertSame('POST', $request->getMethod());
        self::assertSame(TestIssuer::TOKEN_URL, (string) $request->getUri());
        self::assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));
        self::assertSame('application/json', $request->getHeaderLine('Accept'));
        self::assertFalse($request->hasHeader('Authorization'));
        self::assertFalse($request->hasHeader('x-api-key'));
        self::assertSame([
            'grant_type' => 'authorization_code',
            'code' => 'Vb0bVt4CLlr8ZL0kE5mAxv8gS6R0rW3hYqC1pN7dJ2s',
            'code_verifier' => 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk',
            'redirect_uri' => 'https://app.example.com/auth/callback?x=1&y=2',
        ], self::form($request));

        self::assertSame('access.jwt', $tokens->accessToken);
        self::assertSame('id.jwt', $tokens->idToken);
        self::assertSame('test-refresh-token-2', $tokens->refreshToken);
        self::assertSame('Bearer', $tokens->tokenType);
        self::assertSame(299, $tokens->expiresIn);
        self::assertSame('c9abf6cc-2d64-4bf2-b31e-a4d09a070ab1', $tokens->sessionId);
        self::assertNotNull($tokens->rawResponse());
        self::assertSame(200, $tokens->rawResponse()->status);
    }

    public function testRefreshSendsTheRefreshTokenGrant(): void
    {
        $this->issuer->http->on('POST', TestIssuer::TOKEN_URL, TestIssuer::json(200, self::tokenResponse()));

        $tokens = $this->issuer->auth()->refresh('old+refresh/token=');

        $request = $this->issuer->http->last();
        self::assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));
        self::assertSame(['grant_type' => 'refresh_token', 'refresh_token' => 'old+refresh/token='], self::form($request));
        self::assertSame('test-refresh-token-2', $tokens->refreshToken);
        self::assertSame(1, $this->issuer->http->count('POST', TestIssuer::TOKEN_URL));
    }

    public function testTokenEndpointComesFromDiscovery(): void
    {
        $this->issuer->discoveryOverrides = ['token_endpoint' => TestIssuer::ISSUER . '/oauth/token'];
        $this->issuer->http->on('POST', TestIssuer::ISSUER . '/oauth/token', TestIssuer::json(200, self::tokenResponse()));

        $this->issuer->auth()->refresh('r');

        self::assertSame(1, $this->issuer->http->count('POST', TestIssuer::ISSUER . '/oauth/token'));
    }

    public function testInvalidGrantIsABadRequest(): void
    {
        $this->issuer->http->on('POST', TestIssuer::TOKEN_URL, TestIssuer::json(400, ['error' => 'invalid_grant'], ['X-Request-Id' => 'req-9']));

        try {
            $this->issuer->auth()->refresh('used');
            self::fail('Expected a BadRequestException.');
        } catch (BadRequestException $e) {
            self::assertSame(400, $e->status);
            self::assertSame(['error' => 'invalid_grant'], $e->body);
            self::assertSame('req-9', $e->requestId);
            self::assertSame('POST', $e->response->method);
            self::assertSame('/token/', $e->response->path);
            self::assertStringContainsString('invalid_grant', $e->getMessage());
        }
        // Never retried: a second refresh with a consumed token revokes the session.
        self::assertSame(1, $this->issuer->http->count('POST', TestIssuer::TOKEN_URL));
    }

    public function testGetSessionSendsTheBearerTokenAndParsesTheSession(): void
    {
        $this->issuer->http->on('GET', self::SESSION_URL, TestIssuer::json(200, [
            'sub' => '1c9d8b88-1bff-43d1-ad7a-88b2bc0024e9',
            'sid' => 'c9abf6cc-2d64-4bf2-b31e-a4d09a070ab1',
            'application_id' => TestIssuer::AUDIENCE,
            'vendor_data' => 'user-42',
            'amr' => ['passkey'],
            'mfa_verified' => true,
            'email' => 'person@example.com',
            'email_verified' => true,
            'phone' => '+34600000000',
            'verified_identity' => [
                'issuer' => 'https://wallet.didit.me',
                'subject' => 'w-1',
                'shared_session' => ['share_token' => 'st', 'session_id' => 's-1', 'from_application_id' => 'a-1'],
                'claims' => ['over_18' => true, 'country' => 'ES', 'ignored' => 3],
            ],
            'verified' => ['level' => 'full_id', 'over_18' => true, 'given_name' => 'Alex', 'country' => 'ES'],
            'expires_at' => '2026-11-16T10:41:14.347557Z',
            'post_logout_redirect_uri' => 'https://app.example.com/',
            'organizations' => [[
                'organization_id' => '3e9a1f0b-5c2d-4e7f-8a6b-9c0d1e2f3a4b',
                'organization__name' => 'Northwind',
                'role' => 'admin',
                'custom_role' => ['key' => 'ops', 'permissions' => ['payouts.approve']],
            ]],
            'future_field' => 'kept',
        ]));

        $session = $this->issuer->auth()->getSession(self::ACCESS_TOKEN);

        $request = $this->issuer->http->last();
        self::assertSame('GET', $request->getMethod());
        self::assertSame('Bearer ' . self::ACCESS_TOKEN, $request->getHeaderLine('Authorization'));
        self::assertFalse($request->hasHeader('x-api-key'));
        self::assertCount(1, $this->issuer->http->requests, 'getSession() needs no discovery.');

        self::assertSame('1c9d8b88-1bff-43d1-ad7a-88b2bc0024e9', $session->sub);
        self::assertSame('c9abf6cc-2d64-4bf2-b31e-a4d09a070ab1', $session->sid);
        self::assertSame(TestIssuer::AUDIENCE, $session->applicationId);
        self::assertSame('user-42', $session->vendorData);
        self::assertSame(['passkey'], $session->amr);
        self::assertTrue($session->mfaVerified);
        self::assertSame('person@example.com', $session->email);
        self::assertTrue($session->emailVerified);
        self::assertSame('+34600000000', $session->phone);
        self::assertInstanceOf(WalletVerification::class, $session->verifiedIdentity);
        self::assertSame('w-1', $session->verifiedIdentity->subject);
        self::assertSame(['over_18' => true, 'country' => 'ES'], $session->verifiedIdentity->claims);
        self::assertNotNull($session->verifiedIdentity->sharedSession);
        self::assertSame('st', $session->verifiedIdentity->sharedSession->shareToken);
        self::assertInstanceOf(VerifiedClaims::class, $session->verified);
        self::assertSame('full_id', $session->verified->level);
        self::assertNotNull($session->expiresAt);
        self::assertSame('2026-11-16T10:41:14+00:00', $session->expiresAt->format(\DATE_ATOM));
        self::assertSame('https://app.example.com/', $session->postLogoutRedirectUri);
        self::assertIsArray($session->organizations);
        self::assertSame('Northwind', $session->organizations[0]->organizationName);
        self::assertSame('admin', $session->organizations[0]->role);
        self::assertNotNull($session->organizations[0]->customRole);
        self::assertSame(['payouts.approve'], $session->organizations[0]->customRole->permissions);
        self::assertSame('kept', $session['future_field']);
    }

    public function testGetSessionWithNullFields(): void
    {
        $this->issuer->http->on('GET', self::SESSION_URL, TestIssuer::json(200, [
            'sub' => 's', 'email' => null, 'phone' => null, 'verified_identity' => null, 'verified' => null, 'organizations' => [],
        ]));

        $session = $this->issuer->auth()->getSession(self::ACCESS_TOKEN);

        self::assertNull($session->email);
        self::assertNull($session->verifiedIdentity);
        self::assertNull($session->verified);
        self::assertSame([], $session->organizations);
        self::assertTrue($session->has('phone'));
        self::assertFalse($session->has('amr'));
    }

    public function testRejectedAccessTokenIsAnAuthenticationException(): void
    {
        $this->issuer->http->on('GET', self::SESSION_URL, TestIssuer::json(401, ['detail' => 'invalid_token'], ['WWW-Authenticate' => 'Bearer']));

        try {
            $this->issuer->auth()->getSession('expired');
            self::fail('Expected an AuthenticationException.');
        } catch (AuthenticationException $e) {
            self::assertSame(401, $e->status);
            self::assertSame(['detail' => 'invalid_token'], $e->body);
            self::assertSame(['Bearer'], $e->headers['www-authenticate']);
            self::assertStringContainsString('invalid_token', $e->getMessage());
            self::assertStringNotContainsString('expired', $e->getMessage());
        }
    }

    public function testRevokeSessionSendsDelete(): void
    {
        $this->issuer->http->on('DELETE', self::SESSION_URL, new Response(204, ['Cache-Control' => 'no-store']));

        $result = $this->issuer->auth()->revokeSession(self::ACCESS_TOKEN);

        $request = $this->issuer->http->last();
        self::assertSame('DELETE', $request->getMethod());
        self::assertSame('Bearer ' . self::ACCESS_TOKEN, $request->getHeaderLine('Authorization'));
        self::assertSame('', (string) $request->getBody());
        self::assertNotNull($result->rawResponse());
        self::assertSame(204, $result->rawResponse()->status);
    }

    public function testRevokeSessionErrors(): void
    {
        $this->issuer->http->on('DELETE', self::SESSION_URL, TestIssuer::json(401, ['detail' => 'invalid_token']));

        $this->expectException(AuthenticationException::class);
        $this->issuer->auth()->revokeSession(self::ACCESS_TOKEN);
    }

    public function testCreateStepUpSendsJsonAndParsesTheRequiredStepUp(): void
    {
        $this->issuer->http->on('POST', self::STEP_UP_URL, TestIssuer::json(201, [
            'id' => self::STEP_UP_ID,
            'rule' => 'payout',
            'method' => 'selfie',
            'status' => 'pending',
            'level' => null,
            'amount' => '900.00',
            'currency' => 'USD',
            'created_at' => '2026-10-17T10:41:14.894632Z',
            'completed_at' => null,
            'expires_at' => '2026-10-17T11:11:14.894581Z',
            'required' => true,
            'verification_url' => 'https://acme.auth.didit.me/verify/?verification=abc',
            'expires_in' => 1800,
        ]));

        $stepUp = $this->issuer->auth()->createStepUp(self::ACCESS_TOKEN, StepUp::ACTION_PAYOUT, '900.00', 'USD', 'https://app.example.com/payouts/confirm');

        $request = $this->issuer->http->last();
        self::assertSame('POST', $request->getMethod());
        self::assertSame(self::STEP_UP_URL, (string) $request->getUri());
        self::assertSame('Bearer ' . self::ACCESS_TOKEN, $request->getHeaderLine('Authorization'));
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
        self::assertSame('{"action":"payout","amount":"900.00","currency":"USD","return_to":"https://app.example.com/payouts/confirm"}', (string) $request->getBody());

        self::assertTrue($stepUp->required);
        self::assertSame(self::STEP_UP_ID, $stepUp->id);
        self::assertSame(StepUp::RULE_PAYOUT, $stepUp->rule);
        self::assertSame(StepUp::METHOD_SELFIE, $stepUp->method);
        self::assertSame(StepUp::STATUS_PENDING, $stepUp->status);
        self::assertFalse($stepUp->isApproved());
        self::assertNull($stepUp->level);
        self::assertSame('900.00', $stepUp->amount);
        self::assertSame('USD', $stepUp->currency);
        self::assertNotNull($stepUp->createdAt);
        self::assertNull($stepUp->completedAt);
        self::assertNotNull($stepUp->expiresAt);
        self::assertSame('https://acme.auth.didit.me/verify/?verification=abc', $stepUp->verificationUrl);
        self::assertSame(1800, $stepUp->expiresIn);
        self::assertSame(201, $stepUp->rawResponse()?->status);
    }

    public function testCreateStepUpWhenNoRuleApplies(): void
    {
        $this->issuer->http->on('POST', self::STEP_UP_URL, TestIssuer::json(200, ['required' => false]));

        $stepUp = $this->issuer->auth()->createStepUp(self::ACCESS_TOKEN, StepUp::ACTION_BANK, returnTo: 'https://app.example.com/settings/bank');

        self::assertSame('{"action":"bank","return_to":"https://app.example.com/settings/bank"}', (string) $this->issuer->http->last()->getBody());
        self::assertFalse($stepUp->required);
        self::assertNull($stepUp->id);
        self::assertNull($stepUp->status);
        self::assertNull($stepUp->verificationUrl);
        self::assertFalse($stepUp->isApproved());
    }

    public function testCreateStepUpWithoutReturnToSendsOnlyTheAction(): void
    {
        $this->issuer->http->on('POST', self::STEP_UP_URL, TestIssuer::json(200, ['required' => false]));

        $this->issuer->auth()->createStepUp(self::ACCESS_TOKEN, StepUp::ACTION_CONTACT);

        self::assertSame('{"action":"contact"}', (string) $this->issuer->http->last()->getBody());
    }

    public function testCreateStepUpValidationError(): void
    {
        $this->issuer->http->on('POST', self::STEP_UP_URL, TestIssuer::json(400, ['return_to' => 'invalid_redirect_uri']));

        try {
            $this->issuer->auth()->createStepUp(self::ACCESS_TOKEN, StepUp::ACTION_CONTACT, returnTo: 'https://elsewhere.example/');
            self::fail('Expected a BadRequestException.');
        } catch (BadRequestException $e) {
            self::assertSame(['return_to' => 'invalid_redirect_uri'], $e->body);
        }
    }

    public function testRetrieveStepUp(): void
    {
        $url = self::STEP_UP_URL . self::STEP_UP_ID . '/';
        $this->issuer->http->on('GET', $url, TestIssuer::json(200, [
            'id' => self::STEP_UP_ID,
            'rule' => 'payout',
            'method' => 'selfie',
            'status' => 'approved',
            'level' => 'liveness_face_match',
            'amount' => '900.00',
            'currency' => 'USD',
            'created_at' => '2026-10-17T10:41:14.894632Z',
            'completed_at' => '2026-10-17T10:43:02.118204Z',
            'expires_at' => '2026-10-17T11:11:14.894581Z',
        ]));

        $stepUp = $this->issuer->auth()->retrieveStepUp(self::ACCESS_TOKEN, self::STEP_UP_ID);

        $request = $this->issuer->http->last();
        self::assertSame('GET', $request->getMethod());
        self::assertSame($url, (string) $request->getUri());
        self::assertSame('Bearer ' . self::ACCESS_TOKEN, $request->getHeaderLine('Authorization'));
        self::assertTrue($stepUp->required);
        self::assertTrue($stepUp->isApproved());
        self::assertSame(VerifiedClaims::LEVEL_LIVENESS_FACE_MATCH, $stepUp->level);
        self::assertSame('2026-10-17T10:43:02+00:00', $stepUp->completedAt?->format(\DATE_ATOM));
        self::assertNull($stepUp->verificationUrl);
    }

    public function testRetrieveStepUpEncodesTheId(): void
    {
        $this->issuer->http->on('GET', self::STEP_UP_URL . '..%2Fsession/', TestIssuer::json(404, ['detail' => 'No VerificationCheck matches the given query.']));

        try {
            $this->issuer->auth()->retrieveStepUp(self::ACCESS_TOKEN, '../session');
            self::fail('Expected a NotFoundException.');
        } catch (NotFoundException $e) {
            self::assertSame(404, $e->status);
        }
    }

    public function testRetrieveStepUpRefusesAnEmptyId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->issuer->auth()->retrieveStepUp(self::ACCESS_TOKEN, '');
    }

    /**
     * @return iterable<string, array{int, mixed, class-string<ApiStatusException>}>
     */
    public static function errorStatuses(): iterable
    {
        yield '401' => [401, ['detail' => 'invalid_token'], AuthenticationException::class];
        yield '403' => [403, ['detail' => 'origin_not_allowed'], PermissionDeniedException::class];
        yield '404' => [404, ['detail' => 'No VerificationCheck matches the given query.'], NotFoundException::class];
        yield '404 not JSON' => [404, '<html>Not Found</html>', NotFoundException::class];
        yield '429' => [429, ['detail' => 'rate_limited Expected available in 2 seconds.'], RateLimitException::class];
        yield '503' => [503, ['error' => 'temporarily_unavailable'], InternalServerException::class];
    }

    /**
     * @param class-string<ApiStatusException> $class
     */
    #[DataProvider('errorStatuses')]
    public function testErrorStatusesMapToExceptions(int $status, mixed $body, string $class): void
    {
        $url = self::STEP_UP_URL . self::STEP_UP_ID . '/';
        $response = \is_string($body) ? new Response($status, ['Content-Type' => 'text/html'], $body) : TestIssuer::json($status, $body, ['Retry-After' => '2']);
        $this->issuer->http->on('GET', $url, $response);

        try {
            $this->issuer->auth()->retrieveStepUp(self::ACCESS_TOKEN, self::STEP_UP_ID);
            self::fail('Expected an exception.');
        } catch (ApiStatusException $e) {
            self::assertInstanceOf($class, $e);
            self::assertSame($status, $e->status);
            self::assertSame($body, $e->body);
        }
        self::assertSame(1, $this->issuer->http->count('GET', $url));
    }

    /**
     * @return array<string, mixed>
     */
    private static function tokenResponse(): array
    {
        return [
            'access_token' => 'access.jwt',
            'id_token' => 'id.jwt',
            'refresh_token' => 'test-refresh-token-2',
            'token_type' => 'Bearer',
            'expires_in' => 299,
            'session_id' => 'c9abf6cc-2d64-4bf2-b31e-a4d09a070ab1',
        ];
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function form(RequestInterface $request): array
    {
        parse_str((string) $request->getBody(), $fields);

        return $fields;
    }
}
