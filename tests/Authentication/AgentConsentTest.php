<?php

declare(strict_types=1);

namespace Didit\Tests\Authentication;

use Didit\Exception\ApiStatusException;
use Didit\Exception\BadRequestException;
use Didit\Exception\PermissionDeniedException;
use Didit\Tests\Authentication\Support\TestIssuer;
use GuzzleHttp\Psr7\Response;
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
            'response' => ['clientDataJSON' => 'Y2Q', 'authenticatorData' => 'YWQ', 'signature' => 'c2lnSIGNATURE0123456789', 'userHandle' => null],
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
        $this->issuer->http->on('POST', self::CONSENT_URL, TestIssuer::json(400, ['error' => 'invalid_grant: ' . self::CHALLENGE . ' c2lnSIGNATURE0123456789 was used']));

        try {
            $this->issuer->auth()->submitAgentConsent(self::ACCESS_TOKEN, self::CHALLENGE, 'approve', ['response' => ['signature' => 'c2lnSIGNATURE0123456789']]);
            self::fail('Expected a BadRequestException.');
        } catch (BadRequestException $e) {
            self::assertSame('HTTP 400 Bad Request: [redacted]', $e->getMessage());
            self::assertStringNotContainsString(self::CHALLENGE, $e->getMessage());
        }
    }

    public function testEveryAssertionStringIsRedactedAndNoResponseContentIsKept(): void
    {
        $this->issuer->http->on('POST', self::CONSENT_URL, TestIssuer::json(400, ['error' => 'bad TOPIDVALUE0123456789 RAWIDVALUE0123456789 EXTVALUE0123456789xy CDJVALUE0123456789xy']));

        try {
            $this->issuer->auth()->submitAgentConsent(self::ACCESS_TOKEN, self::CHALLENGE, 'approve', ['id' => 'TOPIDVALUE0123456789', 'rawId' => 'RAWIDVALUE0123456789', 'clientExtensionResults' => ['x' => ['y' => 'EXTVALUE0123456789xy']], 'response' => ['clientDataJSON' => 'CDJVALUE0123456789xy', 'signature' => 'c2lnSIGNATURE0123456789']]);
            self::fail('Expected a BadRequestException.');
        } catch (BadRequestException $e) {
            self::assertSame('HTTP 400 Bad Request: [redacted]', $e->getMessage());
            self::assertSame(400, $e->status);
            self::assertStringNotContainsString('TOPIDVALUE0123456789', $e->response->body());
            self::assertStringNotContainsString('c2lnSIGNATURE0123456789', $e->response->body());
            self::assertStringNotContainsString('TOPIDVALUE0123456789', json_encode($e->body, \JSON_THROW_ON_ERROR));
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
            self::assertSame('HTTP 400 Bad Request: [redacted]', $e->getMessage());
            self::assertStringNotContainsString(substr($secret, 0, 16), $e->getMessage());
            self::assertStringNotContainsString(substr($secret, 0, 16), $e->response->body());
            self::assertStringNotContainsString(substr($secret, 0, 16), json_encode($e->body, \JSON_THROW_ON_ERROR));
        }
    }

    public function testAnAssertionValueThatPrefixesAnotherLeavesNoPartOfEitherInTheMessageOrBody(): void
    {
        $this->issuer->http->on('POST', self::CONSENT_URL, TestIssuer::json(400, ['error' => 'echo abcCREDENTIALID0123456 and abcCREDENTIALID0123 end']));

        try {
            $this->issuer->auth()->submitAgentConsent(self::ACCESS_TOKEN, self::CHALLENGE, 'approve', ['id' => 'abcCREDENTIALID0123', 'rawId' => 'abcCREDENTIALID0123456', 'response' => ['signature' => '']]);
            self::fail('Expected a BadRequestException.');
        } catch (BadRequestException $e) {
            self::assertSame('HTTP 400 Bad Request: [redacted]', $e->getMessage());
            foreach ([$e->getMessage(), $e->response->body(), json_encode($e->body, \JSON_THROW_ON_ERROR)] as $text) {
                self::assertStringNotContainsString('abcCREDENTIALID0123', $text);
                self::assertStringNotContainsString('456', $text);
            }
        }
    }

    /**
     * @param array<string, mixed> $assertion
     */
    private function consentError(array $assertion, Response $reply): BadRequestException
    {
        $this->issuer->http->on('POST', self::CONSENT_URL, $reply);

        try {
            $this->issuer->auth()->submitAgentConsent(self::ACCESS_TOKEN, self::CHALLENGE, 'approve', $assertion);
        } catch (BadRequestException $e) {
            return $e;
        }
        self::fail('Expected a BadRequestException.');
    }

    private static function shown(ApiStatusException $e): string
    {
        return $e->getMessage() . "\n" . $e->response->body() . "\n" . json_encode($e->body, \JSON_THROW_ON_ERROR);
    }

    public function testASecretInsideALongerStringRedactsTheWholeString(): void
    {
        $e = $this->consentError(['id' => 'SECRET-0123456789abcdef'], TestIssuer::json(400, ['detail' => 'bad assertion xyzSECRET-0123456789abcdefxyz', 'keep' => 'fine']));

        self::assertSame(['detail' => '[redacted]', 'keep' => 'fine'], $e->body);
        self::assertSame('HTTP 400 Bad Request: [redacted]', $e->getMessage());
    }

    public function testASecretUsedAsAnObjectKeyIsRedactedAndCollidingKeysAreNumbered(): void
    {
        $e = $this->consentError(['id' => 'SECRET-0123456789abcdef'], TestIssuer::json(400, ['detail' => 'no', 'echo-SECRET-0123456789abcdef-1' => 'a', 'echo-SECRET-0123456789abcdef-2' => 'b']));

        self::assertSame(['detail' => 'no', '[redacted]' => 'a', '[redacted]2' => 'b'], $e->body);
        self::assertStringNotContainsString('SECRET-0123456789abcdef', self::shown($e));
    }

    public function testOtherJsonEscapeSpellingsAreRedacted(): void
    {
        $secret = "a/b+\u{e9}-padding0123456";
        $e = $this->consentError(['id' => $secret], new Response(400, ['Content-Type' => 'application/json'], '{"detail":"x","echo":"a\\/b+\\u00E9-padding0123456"}'));

        self::assertSame(['detail' => 'x', 'echo' => '[redacted]'], $e->body);
        self::assertStringNotContainsString('a/b', self::shown($e));
        self::assertStringNotContainsString('00E9', self::shown($e));
        self::assertStringNotContainsString('padding0123456', self::shown($e));
    }

    public function testANonJsonBodyIsReplacedByRedactedEverywhere(): void
    {
        $html = '<html>Bad gateway ' . self::CHALLENGE . '</html>';
        $e = $this->consentError(['id' => 'x'], new Response(400, ['Content-Type' => 'text/html'], $html));

        self::assertSame('[redacted]', $e->body);
        self::assertSame('[redacted]', $e->response->body());
        self::assertStringNotContainsString(self::CHALLENGE, self::shown($e));
    }

    public function testASecretPastTheDetailCapIsRedactedInAnyBody(): void
    {
        $e = $this->consentError(['id' => 'x'], new Response(400, ['Content-Type' => 'text/plain'], str_repeat('x', 600) . self::CHALLENGE));

        self::assertSame('[redacted]', $e->body);
        self::assertStringNotContainsString(self::CHALLENGE, self::shown($e));
    }

    public function testTheOptionsCallRedactsTheParsedBodyToo(): void
    {
        $this->issuer->http->on('POST', self::OPTIONS_URL, TestIssuer::json(400, ['detail' => 'bad ' . self::CHALLENGE]));

        try {
            $this->issuer->auth()->createAgentApprovalOptions(self::ACCESS_TOKEN, self::CHALLENGE);
            self::fail('Expected a BadRequestException.');
        } catch (BadRequestException $e) {
            self::assertSame(['detail' => '[redacted]'], $e->body);
        }
    }

    public function testAStringInsideANestedObjectIsRedacted(): void
    {
        $nested = 'nested-credential-string-0123456789';
        $extension = json_decode('{"credProps":{"deep":[{"value":"' . $nested . '"}]}}');
        $e = $this->consentError(['id' => 'short', 'clientExtensionResults' => $extension], TestIssuer::json(400, ['error' => 'invalid_grant', 'echo' => $nested]));

        self::assertSame(['error' => 'invalid_grant', 'echo' => '[redacted]'], $e->body);
        self::assertStringNotContainsString($nested, self::shown($e));
    }

    public function testSecretsComeFromTheBytesSentNotASecondSerialization(): void
    {
        $sent = 'credential-string-actually-sent-0123';
        $statefulExtension = new class($sent) implements \JsonSerializable {
            private int $calls = 0;

            public function __construct(private readonly string $sent)
            {
            }

            public function jsonSerialize(): mixed
            {
                if (++$this->calls > 1) {
                    throw new \RuntimeException('serialized twice');
                }

                return ['credProps' => ['value' => $this->sent]];
            }
        };
        $e = $this->consentError(['id' => 'short', 'clientExtensionResults' => $statefulExtension], TestIssuer::json(400, ['error' => 'invalid_grant', 'echo' => $sent]));

        self::assertSame(['error' => 'invalid_grant', 'echo' => '[redacted]'], $e->body);
        self::assertStringNotContainsString($sent, self::shown($e));
    }

    public function testTheErrorResponseCarriesTheRedactedBody(): void
    {
        $secret = 'credential-string-0123456789';
        $e = $this->consentError(['id' => $secret], TestIssuer::json(400, ['error' => 'invalid_grant', 'echo' => 'x ' . $secret], ['Content-Length' => '999']));

        self::assertSame('{"error":"invalid_grant","echo":"[redacted]"}', $e->response->body());
        self::assertSame((string) \strlen($e->response->body()), $e->response->header('Content-Length'));
        self::assertStringNotContainsString('credential-str', $e->response->body());
    }

    public function testAnEmptyBodyBecomesRedacted(): void
    {
        $e = $this->consentError(['id' => 'short'], new Response(400, [], ''));

        self::assertSame('[redacted]', $e->body);
        self::assertSame('[redacted]', $e->response->body());
    }

    public function testASafeKeyNamedRedactedAfterASecretKeyKeepsItsName(): void
    {
        $secret = 'SECRET-0123456789abcdef';
        $e = $this->consentError(['id' => $secret], new Response(400, ['Content-Type' => 'application/json'], '{"a-' . $secret . '":"x","[redacted]":"safe"}'));

        self::assertSame(['[redacted]2' => 'x', '[redacted]' => 'safe'], $e->body);
    }

    public function testTheReasonPhraseRequestIdAndHeadersNeverEchoASecret(): void
    {
        $credential = 'credential-string-0123456789';
        $e = $this->consentError(
            ['id' => $credential],
            new Response(400, ['Content-Type' => 'application/json', 'X-Request-Id' => 'req-' . $credential, 'X-Echo' => $credential], '{"error":"invalid_grant"}', '1.1', 'bad ' . $credential),
        );

        self::assertStringNotContainsString($credential, $e->getMessage());
        self::assertSame('[redacted]', $e->requestId);
        self::assertStringNotContainsString($credential, json_encode($e->headers, \JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString($credential, $e->response->reasonPhrase());
    }

    public function testAHeaderNamedAfterASecretIsDropped(): void
    {
        $credential = 'credential-string-0123456789';
        $e = $this->consentError(
            ['id' => $credential],
            new Response(400, ['Content-Type' => 'application/json', 'X-' . $credential => $credential, 'X-Keep' => 'fine'], '{"error":"invalid_grant"}'),
        );

        self::assertSame(['fine'], $e->headers['x-keep'] ?? null);
        self::assertStringNotContainsString($credential, json_encode($e->headers, \JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString($credential, json_encode($e->response->psrResponse->getHeaders(), \JSON_THROW_ON_ERROR));
    }

    public function testAMalformed2xxBodyKeepsTheRequestIdRedacted(): void
    {
        $credential = 'credential-string-0123456789';
        $this->issuer->http->on('POST', self::CONSENT_URL, new Response(200, ['X-Request-Id' => 'req-' . $credential], 'not json ' . $credential));

        try {
            $this->issuer->auth()->submitAgentConsent(self::ACCESS_TOKEN, self::CHALLENGE, 'approve', ['id' => $credential]);
            self::fail('Expected a DiditException.');
        } catch (\Didit\Exception\DiditException $e) {
            self::assertStringNotContainsString($credential, $e->getMessage());
            self::assertStringContainsString('[redacted]', $e->getMessage());
        }
    }

    public function testAVeryDeeplyNestedBodyBecomesRedacted(): void
    {
        $e = $this->consentError(['id' => 'short'], new Response(400, ['Content-Type' => 'application/json'], str_repeat('[', 10000) . str_repeat(']', 10000)));

        self::assertSame('[redacted]', $e->body);
    }

    public function testASuccessfulApprovalOptionsResponseReachesTheCallerUnchanged(): void
    {
        $this->issuer->http->on('POST', self::OPTIONS_URL, TestIssuer::json(200, ['options' => ['challenge' => self::CHALLENGE, 'rpId' => 'acme.auth.didit.me']]));

        $options = $this->issuer->auth()->createAgentApprovalOptions(self::ACCESS_TOKEN, self::CHALLENGE);

        self::assertSame(self::CHALLENGE, $options->options?->challenge);
    }

    public function testAHeaderNameIsMatchedCaseInsensitively(): void
    {
        $credential = 'CredentialString-AbCdEf0123456789';
        $e = $this->consentError(
            ['id' => $credential],
            new Response(400, ['Content-Type' => 'application/json', 'X-Echo-' . $credential => 'v', 'X-Keep' => 'fine'], '{"error":"invalid_grant"}'),
        );

        self::assertSame(['fine'], $e->headers['x-keep'] ?? null);
        self::assertStringNotContainsString('credentialstring', strtolower(json_encode($e->headers, \JSON_THROW_ON_ERROR)));
    }

    public function testASerializerThatThrowsGivesAFixedMessageError(): void
    {
        $bad = new class implements \JsonSerializable {
            public function jsonSerialize(): mixed
            {
                throw new \RuntimeException('credential-string-0123456789 pending-agent-request-secret');
            }
        };

        try {
            $this->issuer->auth()->submitAgentConsent(self::ACCESS_TOKEN, self::CHALLENGE, 'approve', ['id' => 'x', 'clientExtensionResults' => $bad]);
            self::fail('Expected a DiditException.');
        } catch (\Didit\Exception\DiditException $e) {
            self::assertSame('Could not encode the agent consent request.', $e->getMessage());
            self::assertNull($e->getPrevious());
            self::assertStringNotContainsString('credential-string', (string) $e);
        }
    }

    public function testABodyThatFailsMidReadIsAFixedMessageConnectionError(): void
    {
        $broken = new class implements \Psr\Http\Message\StreamInterface {
            public function __toString(): string
            {
                return '';
            }

            public function close(): void
            {
            }

            public function detach()
            {
                return null;
            }

            public function getSize(): ?int
            {
                return null;
            }

            public function tell(): int
            {
                return 0;
            }

            public function eof(): bool
            {
                return false;
            }

            public function isSeekable(): bool
            {
                return false;
            }

            public function seek(int $offset, int $whence = \SEEK_SET): void
            {
            }

            public function rewind(): void
            {
            }

            public function isWritable(): bool
            {
                return false;
            }

            public function write(string $string): int
            {
                return 0;
            }

            public function isReadable(): bool
            {
                return true;
            }

            public function read(int $length): string
            {
                throw new \RuntimeException('credential-string-0123456789 broken stream');
            }

            public function getContents(): string
            {
                throw new \RuntimeException('credential-string-0123456789 broken stream');
            }

            public function getMetadata(?string $key = null)
            {
                return null;
            }
        };
        $this->issuer->http->on('POST', self::CONSENT_URL, new Response(200, ['Content-Type' => 'application/json'], $broken));

        try {
            $this->issuer->auth()->submitAgentConsent(self::ACCESS_TOKEN, self::CHALLENGE, 'approve', ['id' => 'x']);
            self::fail('Expected an ApiConnectionException.');
        } catch (\Didit\Exception\ApiConnectionException $e) {
            self::assertNull($e->getPrevious());
            self::assertStringNotContainsString('credential-string', (string) $e);
        }
    }

    public function testAMalformed2xxKeepsNoPreviousException(): void
    {
        $credential = 'credential-string-0123456789';
        $this->issuer->http->on('POST', self::CONSENT_URL, new Response(200, ['Content-Type' => 'application/json'], '{not json ' . $credential));

        try {
            $this->issuer->auth()->submitAgentConsent(self::ACCESS_TOKEN, self::CHALLENGE, 'approve', ['id' => $credential]);
            self::fail('Expected a DiditException.');
        } catch (\Didit\Exception\DiditException $e) {
            self::assertNull($e->getPrevious());
            self::assertStringNotContainsString($credential, (string) $e);
        }
    }

    public function testATransportFailureKeepsNoUnderlyingTextOrException(): void
    {
        $credential = 'cred"ential\\string-0123456789';
        $this->issuer->http->on('POST', self::CONSENT_URL, static function (\Psr\Http\Message\RequestInterface $request): never {
            throw new class('write failed: ' . $request->getBody()) extends \RuntimeException implements \Psr\Http\Client\ClientExceptionInterface {
            };
        });

        try {
            $this->issuer->auth()->submitAgentConsent(self::ACCESS_TOKEN, self::CHALLENGE, 'approve', ['id' => $credential]);
            self::fail('Expected an ApiConnectionException.');
        } catch (\Didit\Exception\ApiConnectionException $e) {
            self::assertNull($e->getPrevious());
            self::assertStringNotContainsString('ential', (string) $e);
            self::assertStringNotContainsString('write failed', (string) $e);
        }
    }

    public function testErrorCodesAndShortAssertionStringsStayReadable(): void
    {
        $long = 'Y3JlZGVudGlhbC1pZC0xMjM0NTY3ODkw';
        $e = $this->consentError(['id' => $long, 'type' => 'public-key'], TestIssuer::json(400, ['error' => 'invalid_grant', 'detail' => 'id public-key ' . $long]));

        self::assertSame(['error' => 'invalid_grant', 'detail' => '[redacted]'], $e->body);
        self::assertStringContainsString('invalid_grant', self::shown($e));
        self::assertStringNotContainsString($long, self::shown($e));
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
