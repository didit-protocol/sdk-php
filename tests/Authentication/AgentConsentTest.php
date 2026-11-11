<?php

declare(strict_types=1);

namespace Didit\Tests\Authentication;

use Didit\Exception\ApiStatusException;
use Didit\Exception\BadRequestException;
use Didit\Exception\PermissionDeniedException;
use Didit\Tests\Authentication\Support\TestIssuer;
use PHPUnit\Framework\TestCase;

final class AgentConsentTest extends TestCase
{
    private const CONSENT_URL = TestIssuer::ISSUER . '/agents/consent/';
    private const OPTIONS_URL = TestIssuer::ISSUER . '/agents/consent/passkey/';
    private const ACCESS_TOKEN = 'test-access-token';
    private const CHALLENGE = 'pending-agent-request-secret';

    private TestIssuer $issuer;

    protected function setUp(): void
    {
        $this->issuer = new TestIssuer();
    }

    public function testApproveSendsTheAssertionAndParsesTheApproval(): void
    {
        $this->issuer->http->on('POST', self::CONSENT_URL, TestIssuer::json(200, [
            'code' => 'single-use-agent-code',
            'redirect_uri' => 'https://agent.example/callback',
            'state' => 'request-state',
        ], ['Cache-Control' => 'no-store']));
        $assertion = [
            'id' => 'Y3JlZA',
            'rawId' => 'Y3JlZA',
            'type' => 'public-key',
            'response' => ['clientDataJSON' => 'Y2Q', 'authenticatorData' => 'YWQ', 'signature' => 'c2ln', 'userHandle' => null],
        ];

        $result = $this->issuer->auth()->submitAgentConsent(self::ACCESS_TOKEN, self::CHALLENGE, 'approve', $assertion, ['read']);

        $request = $this->issuer->http->last();
        self::assertSame('POST', $request->getMethod());
        self::assertSame(self::CONSENT_URL, (string) $request->getUri());
        self::assertSame('Bearer ' . self::ACCESS_TOKEN, $request->getHeaderLine('Authorization'));
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
        self::assertSame(
            ['challenge' => self::CHALLENGE, 'decision' => 'approve', 'assertion' => $assertion, 'scopes' => ['read']],
            json_decode((string) $request->getBody(), true),
        );
        self::assertTrue($result->isApproved());
        self::assertFalse($result->isDenied());
        self::assertSame('single-use-agent-code', $result->code);
        self::assertSame('https://agent.example/callback', $result->redirectUri);
        self::assertSame('request-state', $result->state);
        self::assertNull($result->status);
        self::assertSame(200, $result->rawResponse()?->status);
    }

    public function testApproveSendsOrganizationIdsAndLifetime(): void
    {
        $this->issuer->http->on('POST', self::CONSENT_URL, TestIssuer::json(200, ['code' => 'c', 'redirect_uri' => 'https://agent.example/callback', 'state' => 's']));
        $id = '94efc2c3-ca84-42fe-900b-6fb61669a7a1';

        $this->issuer->auth()->submitAgentConsent(self::ACCESS_TOKEN, self::CHALLENGE, 'approve', organizationIds: [$id], lifetime: 604800);

        self::assertSame(
            ['challenge' => self::CHALLENGE, 'decision' => 'approve', 'organization_ids' => [$id], 'lifetime' => 604800],
            json_decode((string) $this->issuer->http->last()->getBody(), true),
        );
    }

    public function testInvalidOrganizationIdsAndLifetimeAreRejectedBeforeSending(): void
    {
        $auth = $this->issuer->auth();
        $cases = [
            ['organizationIds' => ['not-a-uuid'], 'lifetime' => null],
            ['organizationIds' => array_fill(0, 101, '94efc2c3-ca84-42fe-900b-6fb61669a7a1'), 'lifetime' => null],
            ['organizationIds' => null, 'lifetime' => 3600],
        ];
        foreach ($cases as $case) {
            try {
                $auth->submitAgentConsent(self::ACCESS_TOKEN, self::CHALLENGE, 'approve', organizationIds: $case['organizationIds'], lifetime: $case['lifetime']);
                self::fail('Expected an InvalidArgumentException.');
            } catch (\InvalidArgumentException) {
                self::assertSame(0, $this->issuer->http->count('POST', self::CONSENT_URL));
            }
        }
    }

    public function testDenyNeedsNoAssertionAndParsesTheDenial(): void
    {
        $this->issuer->http->on('POST', self::CONSENT_URL, TestIssuer::json(200, ['status' => 'denied']));

        $result = $this->issuer->auth()->submitAgentConsent(self::ACCESS_TOKEN, self::CHALLENGE, 'deny');

        self::assertSame('{"challenge":"pending-agent-request-secret","decision":"deny"}', (string) $this->issuer->http->last()->getBody());
        self::assertTrue($result->isDenied());
        self::assertFalse($result->isApproved());
        self::assertSame('denied', $result->status);
        self::assertNull($result->code);
        self::assertNull($result->redirectUri);
    }

    public function testCreateApprovalOptionsParsesTheWebAuthnOptions(): void
    {
        $this->issuer->http->on('POST', self::OPTIONS_URL, TestIssuer::json(200, [
            'options' => [
                'challenge' => 'c3ludGhldGljLWNoYWxsZW5nZQ',
                'rpId' => 'acme.auth.didit.me',
                'timeout' => 60000,
                'userVerification' => 'required',
                'allowCredentials' => [['id' => 'Y3JlZA', 'type' => 'public-key', 'transports' => ['internal']]],
            ],
        ], ['Cache-Control' => 'no-store']));

        $result = $this->issuer->auth()->createAgentApprovalOptions(self::ACCESS_TOKEN, self::CHALLENGE);

        $request = $this->issuer->http->last();
        self::assertSame(self::OPTIONS_URL, (string) $request->getUri());
        self::assertSame('Bearer ' . self::ACCESS_TOKEN, $request->getHeaderLine('Authorization'));
        self::assertSame('{"challenge":"pending-agent-request-secret"}', (string) $request->getBody());
        $options = $result->options;
        self::assertNotNull($options);
        self::assertSame('c3ludGhldGljLWNoYWxsZW5nZQ', $options->challenge);
        self::assertSame('acme.auth.didit.me', $options->rpId);
        self::assertSame(60000, $options->timeout);
        self::assertSame('required', $options->userVerification);
        self::assertCount(1, $options->allowCredentials ?? []);
        self::assertSame('Y3JlZA', $options->allowCredentials[0]->id ?? null);
        self::assertSame(['internal'], $options->allowCredentials[0]->transports ?? null);
    }

    public function testInvalidGrantIsABadRequestAndNeverEchoesTheSecrets(): void
    {
        $this->issuer->http->on('POST', self::CONSENT_URL, TestIssuer::json(400, ['error' => 'invalid_grant: ' . self::CHALLENGE . ' c2ln was used']));

        try {
            $this->issuer->auth()->submitAgentConsent(self::ACCESS_TOKEN, self::CHALLENGE, 'approve', ['response' => ['signature' => 'c2ln']]);
            self::fail('Expected a BadRequestException.');
        } catch (BadRequestException $e) {
            self::assertSame('HTTP 400 Bad Request: invalid_grant: [redacted] [redacted] was used', $e->getMessage());
            self::assertStringNotContainsString(self::CHALLENGE, $e->getMessage());
        }
    }

    public function testEveryAssertionStringIsRedactedAndNoResponseContentIsKept(): void
    {
        $this->issuer->http->on('POST', self::CONSENT_URL, TestIssuer::json(400, ['error' => 'bad TOPID RAWID EXT CDJ']));

        try {
            $this->issuer->auth()->submitAgentConsent(self::ACCESS_TOKEN, self::CHALLENGE, 'approve', ['id' => 'TOPID', 'rawId' => 'RAWID', 'clientExtensionResults' => ['x' => ['y' => 'EXT']], 'response' => ['clientDataJSON' => 'CDJ', 'signature' => 'c2ln']]);
            self::fail('Expected a BadRequestException.');
        } catch (BadRequestException $e) {
            self::assertSame('HTTP 400 Bad Request: bad [redacted] [redacted] [redacted] [redacted]', $e->getMessage());
            self::assertSame(400, $e->status);
            self::assertStringNotContainsString('TOPID', $e->response->body());
            self::assertStringNotContainsString('c2ln', $e->response->body());
            self::assertStringNotContainsString('TOPID', json_encode($e->body, \JSON_THROW_ON_ERROR));
        }
    }

    public function testAnAssertionLongerThanTheDetailLimitLeavesNoPrefixInTheMessageOrBody(): void
    {
        $secret = str_repeat('Zq9', 400);
        $this->issuer->http->on('POST', self::CONSENT_URL, TestIssuer::json(400, ['error' => 'echo ' . $secret . ' end']));

        try {
            $this->issuer->auth()->submitAgentConsent(self::ACCESS_TOKEN, self::CHALLENGE, 'approve', ['response' => ['clientDataJSON' => $secret]]);
            self::fail('Expected a BadRequestException.');
        } catch (BadRequestException $e) {
            self::assertSame('HTTP 400 Bad Request: echo [redacted] end', $e->getMessage());
            self::assertStringNotContainsString(substr($secret, 0, 16), $e->getMessage());
            self::assertStringNotContainsString(substr($secret, 0, 16), $e->response->body());
            self::assertStringNotContainsString(substr($secret, 0, 16), json_encode($e->body, \JSON_THROW_ON_ERROR));
        }
    }

    public function testAnAssertionValueThatPrefixesAnotherLeavesNoPartOfEitherInTheMessageOrBody(): void
    {
        $this->issuer->http->on('POST', self::CONSENT_URL, TestIssuer::json(400, ['error' => 'echo abcdef and abc end']));

        try {
            $this->issuer->auth()->submitAgentConsent(self::ACCESS_TOKEN, self::CHALLENGE, 'approve', ['id' => 'abc', 'rawId' => 'abcdef', 'response' => ['signature' => '']]);
            self::fail('Expected a BadRequestException.');
        } catch (BadRequestException $e) {
            self::assertSame('HTTP 400 Bad Request: echo [redacted] and [redacted] end', $e->getMessage());
            foreach ([$e->getMessage(), $e->response->body(), json_encode($e->body, \JSON_THROW_ON_ERROR)] as $text) {
                self::assertStringNotContainsString('abc', $text);
                self::assertStringNotContainsString('def', $text);
            }
        }
    }

    public function testPasskeyRequiredIsPermissionDenied(): void
    {
        $this->issuer->http->on('POST', self::CONSENT_URL, TestIssuer::json(403, ['detail' => 'passkey_required']));

        try {
            $this->issuer->auth()->submitAgentConsent(self::ACCESS_TOKEN, self::CHALLENGE, 'approve');
            self::fail('Expected a PermissionDeniedException.');
        } catch (PermissionDeniedException $e) {
            self::assertSame(['detail' => 'passkey_required'], $e->body);
        }
    }

    public function testApprovalOptionsErrorsAreNeverRetried(): void
    {
        $this->issuer->http->on('POST', self::OPTIONS_URL, TestIssuer::json(503, ['detail' => 'temporarily_unavailable']));

        try {
            $this->issuer->auth()->createAgentApprovalOptions(self::ACCESS_TOKEN, self::CHALLENGE);
            self::fail('Expected an ApiStatusException.');
        } catch (ApiStatusException $e) {
            self::assertSame(503, $e->status);
        }
        self::assertSame(1, $this->issuer->http->count('POST', self::OPTIONS_URL));
    }
}
