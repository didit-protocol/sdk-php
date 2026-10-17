<?php

declare(strict_types=1);

namespace Didit\Tests\Webhooks;

use Didit\Exception\ConfigurationException;
use Didit\Exception\WebhookVerificationException;
use Didit\Webhooks\Event;
use Didit\Webhooks\Events\StatusUpdatedEvent;
use Didit\Webhooks\VerifiesWebhooks;
use Didit\Webhooks\Webhook;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WebhookTest extends TestCase
{
    private const SECRET = 'whsec_unit_test_secret';

    private const PREVIOUS_SECRET = 'whsec_unit_test_previous';

    private const TIMESTAMP = 1760702400;

    private const NOW = 1760702410;

    /** ASCII-only and already canonical, so X-Signature and X-Signature-V2 sign the same bytes. */
    private const BODY = '{"application_id":"app-1","created_at":1760702400,"environment":"sandbox","event_id":"evt-1","session_id":"sess-1","status":"Approved","timestamp":1760702400,"webhook_type":"status.updated"}';

    public function testValidDeliveryReturnsTheTypedEvent(): void
    {
        $event = Webhook::verify(self::BODY, self::headers(), self::SECRET, now: self::NOW);

        self::assertInstanceOf(StatusUpdatedEvent::class, $event);
        self::assertSame('sess-1', $event->sessionId);
        self::assertSame('X-Signature', $event->verifiedWith());
        self::assertTrue($event->bodyAuthenticated());
    }

    public function testNowDefaultsToTheClock(): void
    {
        $body = str_replace((string) self::TIMESTAMP, (string) time(), self::BODY);
        $event = Webhook::verify($body, ['X-Signature' => self::sign($body)], self::SECRET);

        self::assertSame('X-Signature', $event->verifiedWith());
    }

    public function testASecretListAcceptsAnyOfItsSecrets(): void
    {
        $headers = ['X-Signature' => self::sign(self::BODY, self::PREVIOUS_SECRET), 'X-Timestamp' => (string) self::TIMESTAMP];

        $event = Webhook::verify(self::BODY, $headers, [self::SECRET, self::PREVIOUS_SECRET], now: self::NOW);
        self::assertSame('X-Signature', $event->verifiedWith());

        $keyed = Webhook::verify(self::BODY, $headers, ['new' => self::SECRET, 'old' => self::PREVIOUS_SECRET], now: self::NOW);
        self::assertSame('X-Signature', $keyed->verifiedWith());

        self::assertRejected(WebhookVerificationException::INVALID_SIGNATURE, static fn () => Webhook::verify(self::BODY, $headers, [self::SECRET], now: self::NOW));
    }

    public function testPreviousSignatureHeadersCoverARotation(): void
    {
        // Didit signs with the new secret in X-Signature and with the replaced one in X-Signature-Previous.
        $headers = [
            'X-Signature' => self::sign(self::BODY, 'whsec_brand_new'),
            'X-Signature-V2' => self::sign(self::BODY, 'whsec_brand_new'),
            'X-Signature-Previous' => self::sign(self::BODY),
            'X-Signature-V2-Previous' => self::sign(self::BODY),
        ];
        $event = Webhook::verify(self::BODY, $headers, self::SECRET, now: self::NOW);

        self::assertSame('X-Signature-Previous', $event->verifiedWith());
        self::assertTrue($event->bodyAuthenticated());

        unset($headers['X-Signature-Previous']);
        self::assertSame('X-Signature-V2-Previous', Webhook::verify(self::BODY, $headers, self::SECRET, now: self::NOW)->verifiedWith());
    }

    /**
     * @return array<string, array{string|list<string>}>
     */
    public static function emptySecrets(): array
    {
        return [
            'empty string' => [''],
            'empty list' => [[]],
            'list with an empty secret' => [[self::SECRET, '']],
        ];
    }

    /**
     * @param string|list<string> $secret
     */
    #[DataProvider('emptySecrets')]
    public function testAnEmptySecretIsAConfigurationError(string|array $secret): void
    {
        $this->expectException(ConfigurationException::class);
        Webhook::verify(self::BODY, self::headers(), $secret, now: self::NOW);
    }

    /**
     * @param string|list<string> $secret
     */
    #[DataProvider('emptySecrets')]
    public function testAnEmptySecretIsAConfigurationErrorForParsedPayloads(string|array $secret): void
    {
        $this->expectException(ConfigurationException::class);
        Webhook::verifyParsed(self::payload(), self::headers(), $secret, now: self::NOW);
    }

    public function testTheSecretIsCheckedBeforeTheBody(): void
    {
        $this->expectException(ConfigurationException::class);
        Webhook::verify('not json', [], '');
    }

    public function testANegativeToleranceIsAConfigurationError(): void
    {
        $this->expectException(ConfigurationException::class);
        Webhook::verify(self::BODY, self::headers(), self::SECRET, -1, now: self::NOW);
    }

    /**
     * @return array<string, array{array<string, string|list<string>>}>
     */
    public static function headerShapes(): array
    {
        $signature = self::sign(self::BODY);
        $timestamp = (string) self::TIMESTAMP;

        return [
            'getallheaders()' => [['X-Signature' => $signature, 'X-Timestamp' => $timestamp]],
            'lower case, Symfony and Laravel headers->all()' => [['x-signature' => [$signature], 'x-timestamp' => [$timestamp]]],
            'upper case' => [['X-SIGNATURE' => $signature, 'X-TIMESTAMP' => $timestamp]],
            'mixed case' => [['x-SiGnAtUrE' => $signature, 'X-timestamp' => $timestamp]],
            'several values, first malformed' => [['X-Signature' => ['garbage', $signature]]],
            'comma-joined values' => [['X-Signature' => 'garbage, ' . $signature]],
            'surrounding whitespace' => [['X-Signature' => ' ' . $signature . "\t", 'X-Timestamp' => ' ' . $timestamp . ' ']],
        ];
    }

    /**
     * @param array<string, string|list<string>> $headers
     */
    #[DataProvider('headerShapes')]
    public function testHeaderShapesAndNameCase(array $headers): void
    {
        $event = Webhook::verify(self::BODY, $headers, self::SECRET, now: self::NOW);

        self::assertSame('X-Signature', $event->verifiedWith());
    }

    public function testHeaderNamesIgnoreTheLocale(): void
    {
        $previous = setlocale(\LC_CTYPE, '0');
        setlocale(\LC_CTYPE, 'tr_TR.ISO-8859-9', 'tr_TR', 'tr_TR.UTF-8');
        try {
            $event = Webhook::verify(self::BODY, ['X-SIGNATURE' => self::sign(self::BODY)], self::SECRET, now: self::NOW);
            self::assertSame('X-Signature', $event->verifiedWith());
        } finally {
            setlocale(\LC_CTYPE, false === $previous ? 'C' : $previous);
        }
    }

    public function testPsr7MessagesProvideTheHeaders(): void
    {
        $headers = ['x-signature-v2' => self::sign(self::BODY), 'X-Timestamp' => (string) self::TIMESTAMP];

        $request = new ServerRequest('POST', '/webhooks/didit', $headers, self::BODY);
        $event = Webhook::verify((string) $request->getBody(), $request, self::SECRET, now: self::NOW);
        self::assertSame('X-Signature-V2', $event->verifiedWith());

        $plain = new Request('POST', '/webhooks/didit', $headers);
        self::assertSame('X-Signature-V2', Webhook::verify(self::BODY, $plain, self::SECRET, now: self::NOW)->verifiedWith());

        $mismatch = new Request('POST', '/', ['X-Signature' => self::sign(self::BODY), 'X-Timestamp' => '1']);
        self::assertRejected(WebhookVerificationException::TIMESTAMP_MISMATCH, static fn () => Webhook::verify(self::BODY, $mismatch, self::SECRET, now: self::NOW));

        self::assertRejected(WebhookVerificationException::MISSING_SIGNATURE, static fn () => Webhook::verify(self::BODY, new Response(200), self::SECRET, now: self::NOW));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedSignatures(): array
    {
        $signature = self::sign(self::BODY);

        return [
            'upper-case hex of the right signature' => [strtoupper($signature)],
            'one character short' => [substr($signature, 0, 63)],
            'one character long' => [$signature . '0'],
            'prefixed' => ['sha256=' . $signature],
            'base64' => [base64_encode((string) hex2bin($signature))],
            'not hex' => [str_repeat('z', 64)],
            'inner whitespace' => [substr($signature, 0, 32) . ' ' . substr($signature, 32)],
        ];
    }

    #[DataProvider('malformedSignatures')]
    public function testOnlyWellFormedLowerCaseHexIsCompared(string $value): void
    {
        self::assertRejected(
            WebhookVerificationException::INVALID_SIGNATURE,
            static fn () => Webhook::verify(self::BODY, ['X-Signature' => $value, 'X-Signature-V2' => $value], self::SECRET, now: self::NOW),
        );
    }

    public function testAnEmptySignatureHeaderCountsAsAbsent(): void
    {
        self::assertRejected(
            WebhookVerificationException::MISSING_SIGNATURE,
            static fn () => Webhook::verify(self::BODY, ['X-Signature' => '', 'X-Signature-V2' => [' '], 'X-Timestamp' => (string) self::TIMESTAMP], self::SECRET, now: self::NOW),
        );
    }

    public function testAWrongSignatureIsInvalid(): void
    {
        self::assertRejected(
            WebhookVerificationException::INVALID_SIGNATURE,
            static fn () => Webhook::verify(self::BODY, ['X-Signature' => self::sign(self::BODY, 'whsec_other')], self::SECRET, now: self::NOW),
        );
    }

    public function testATamperedBodyIsInvalid(): void
    {
        $tampered = str_replace('"Approved"', '"Declined"', self::BODY);
        self::assertRejected(
            WebhookVerificationException::INVALID_SIGNATURE,
            static fn () => Webhook::verify($tampered, self::headers(), self::SECRET, now: self::NOW),
        );
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function badTimestamps(): array
    {
        return [
            'missing' => ['{"webhook_type":"status.updated"}', WebhookVerificationException::MISSING_TIMESTAMP],
            'null' => ['{"timestamp":null}', WebhookVerificationException::MISSING_TIMESTAMP],
            'string' => ['{"timestamp":"1760702400"}', WebhookVerificationException::MISSING_TIMESTAMP],
            'float' => ['{"timestamp":1760702400.5}', WebhookVerificationException::MISSING_TIMESTAMP],
            'integral float' => ['{"timestamp":1760702400.0}', WebhookVerificationException::MISSING_TIMESTAMP],
            'above PHP_INT_MAX' => ['{"timestamp":92233720368547758070}', WebhookVerificationException::MISSING_TIMESTAMP],
            'nested only' => ['{"data":{"timestamp":1760702400}}', WebhookVerificationException::MISSING_TIMESTAMP],
            'empty object' => ['{}', WebhookVerificationException::MISSING_TIMESTAMP],
            'array body' => ['[{"timestamp":1760702400}]', WebhookVerificationException::INVALID_PAYLOAD],
            'empty array body' => ['[]', WebhookVerificationException::INVALID_PAYLOAD],
            'string body' => ['"hello"', WebhookVerificationException::INVALID_PAYLOAD],
            'number body' => ['1760702400', WebhookVerificationException::INVALID_PAYLOAD],
            'null body' => ['null', WebhookVerificationException::INVALID_PAYLOAD],
            'empty body' => ['', WebhookVerificationException::INVALID_PAYLOAD],
            'not JSON' => ['timestamp=1760702400', WebhookVerificationException::INVALID_PAYLOAD],
            'truncated JSON' => ['{"timestamp":1760702400', WebhookVerificationException::INVALID_PAYLOAD],
        ];
    }

    /**
     * The body is checked before the signature, so these fail with the body's
     * problem even though they are correctly signed.
     */
    #[DataProvider('badTimestamps')]
    public function testBodiesWithoutAnIntegerTimestamp(string $body, string $reason): void
    {
        self::assertRejected(
            $reason,
            static fn () => Webhook::verify($body, ['X-Signature' => self::sign($body)], self::SECRET, now: self::NOW),
        );
    }

    public function testTheTimestampHeaderMustEqualTheBody(): void
    {
        foreach (['1760702401', '1760702400.0', '01760702400', 'abc'] as $header) {
            self::assertRejected(
                WebhookVerificationException::TIMESTAMP_MISMATCH,
                static fn () => Webhook::verify(self::BODY, ['X-Signature' => self::sign(self::BODY), 'X-Timestamp' => $header], self::SECRET, now: self::NOW),
            );
        }
        // Without X-Timestamp the body's timestamp alone is checked.
        $event = Webhook::verify(self::BODY, ['X-Signature' => self::sign(self::BODY)], self::SECRET, now: self::NOW);
        self::assertSame(self::TIMESTAMP, $event->timestamp);
    }

    public function testTolerance(): void
    {
        $headers = self::headers();
        $verify = static fn (int $now, int $tolerance = 300) => Webhook::verify(self::BODY, $headers, self::SECRET, $tolerance, now: $now);

        self::assertSame('X-Signature', $verify(self::TIMESTAMP + 300)->verifiedWith());
        self::assertSame('X-Signature', $verify(self::TIMESTAMP - 300)->verifiedWith());
        self::assertSame('X-Signature', $verify(self::TIMESTAMP, 0)->verifiedWith());
        self::assertSame('X-Signature', $verify(self::TIMESTAMP + 3600, 3600)->verifiedWith());
        self::assertRejected(WebhookVerificationException::TIMESTAMP_OUT_OF_TOLERANCE, static fn () => $verify(self::TIMESTAMP + 301));
        self::assertRejected(WebhookVerificationException::TIMESTAMP_OUT_OF_TOLERANCE, static fn () => $verify(self::TIMESTAMP - 301));
        self::assertRejected(WebhookVerificationException::TIMESTAMP_OUT_OF_TOLERANCE, static fn () => $verify(self::TIMESTAMP + 1, 0));
        // A stale delivery is rejected for its age before its signature is looked at.
        self::assertRejected(
            WebhookVerificationException::TIMESTAMP_OUT_OF_TOLERANCE,
            static fn () => Webhook::verify(self::BODY, ['X-Signature' => self::sign(self::BODY, 'whsec_other')], self::SECRET, now: self::TIMESTAMP + 301),
        );
    }

    public function testExtremeTimestampsAreOutOfTolerance(): void
    {
        foreach ([\PHP_INT_MAX, \PHP_INT_MIN, 0, -1] as $timestamp) {
            $body = '{"timestamp":' . $timestamp . '}';
            self::assertRejected(
                WebhookVerificationException::TIMESTAMP_OUT_OF_TOLERANCE,
                static fn () => Webhook::verify($body, ['X-Signature' => self::sign($body)], self::SECRET, now: self::NOW),
            );
        }
    }

    public function testNoSignatureHeaderIsMissingSignature(): void
    {
        self::assertRejected(
            WebhookVerificationException::MISSING_SIGNATURE,
            static fn () => Webhook::verify(self::BODY, ['X-Timestamp' => (string) self::TIMESTAMP, 'Content-Type' => 'application/json'], self::SECRET, now: self::NOW),
        );
        self::assertRejected(
            WebhookVerificationException::MISSING_SIGNATURE,
            static fn () => Webhook::verify(self::BODY, [], self::SECRET, now: self::NOW),
        );
    }

    public function testSimpleSignatureCountsOnlyWhenAllowed(): void
    {
        $headers = ['X-Signature-Simple' => self::sign('1760702400:sess-1:Approved:status.updated'), 'X-Timestamp' => (string) self::TIMESTAMP];

        try {
            Webhook::verify(self::BODY, $headers, self::SECRET, now: self::NOW);
            self::fail('X-Signature-Simple must not count without allowSimple');
        } catch (WebhookVerificationException $exception) {
            // Consistent with the other SDKs: a signature is present, it just does not count.
            self::assertSame(WebhookVerificationException::INVALID_SIGNATURE, $exception->reason);
            self::assertStringContainsString('allowSimple', $exception->getMessage());
        }

        $event = Webhook::verify(self::BODY, $headers, self::SECRET, allowSimple: true, now: self::NOW);
        self::assertSame('X-Signature-Simple', $event->verifiedWith());
        self::assertFalse($event->bodyAuthenticated());
    }

    public function testSimpleSignatureDoesNotCoverTheBody(): void
    {
        $body = str_replace('"environment":"sandbox"', '"environment":"live"', self::BODY);
        $headers = [
            'X-Signature' => self::sign(self::BODY),
            'X-Signature-V2' => self::sign(self::BODY),
            'X-Signature-Simple' => self::sign('1760702400:sess-1:Approved:status.updated'),
        ];

        self::assertRejected(WebhookVerificationException::INVALID_SIGNATURE, static fn () => Webhook::verify($body, $headers, self::SECRET, now: self::NOW));
        // The altered field is outside the four that Simple signs, so only bodyAuthenticated() tells.
        $event = Webhook::verify($body, $headers, self::SECRET, allowSimple: true, now: self::NOW);
        self::assertSame('X-Signature-Simple', $event->verifiedWith());
        self::assertFalse($event->bodyAuthenticated());
        self::assertSame('live', $event->environment);
    }

    public function testABodySignatureWinsOverSimple(): void
    {
        $headers = self::headers() + ['X-Signature-Simple' => self::sign('1760702400:sess-1:Approved:status.updated')];
        $event = Webhook::verify(self::BODY, $headers, self::SECRET, allowSimple: true, now: self::NOW);

        self::assertSame('X-Signature', $event->verifiedWith());
        self::assertTrue($event->bodyAuthenticated());
    }

    public function testSimplePreviousCoversARotation(): void
    {
        $headers = [
            'X-Signature-Simple' => self::sign('1760702400:sess-1:Approved:status.updated', 'whsec_brand_new'),
            'X-Signature-Simple-Previous' => self::sign('1760702400:sess-1:Approved:status.updated'),
        ];
        $event = Webhook::verify(self::BODY, $headers, self::SECRET, allowSimple: true, now: self::NOW);

        self::assertSame('X-Signature-Simple-Previous', $event->verifiedWith());
        self::assertFalse($event->bodyAuthenticated());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function simpleMessages(): array
    {
        // The backend builds ":".join(str(data.get(k, "")) ...) in Python.
        return [
            'missing fields are empty' => ['{"timestamp":1760702400,"status":"Approved","webhook_type":"transaction.status.updated"}', '1760702400::Approved:transaction.status.updated'],
            'null is None' => ['{"timestamp":1760702400,"session_id":null,"status":"Approved","webhook_type":"status.updated"}', '1760702400:None:Approved:status.updated'],
            'booleans are True and False' => ['{"timestamp":1760702400,"session_id":true,"status":false,"webhook_type":"status.updated"}', '1760702400:True:False:status.updated'],
            'floats in repr form' => ['{"timestamp":1760702400,"session_id":87.0,"status":1e-05,"webhook_type":1e16}', '1760702400:87.0:1e-05:1e+16'],
            'big integers keep their digits' => ['{"timestamp":1760702400,"session_id":123456789012345678901234567890,"status":"x","webhook_type":"y"}', '1760702400:123456789012345678901234567890:x:y'],
            'non-ASCII stays UTF-8' => ['{"timestamp":1760702400,"session_id":"\\u00e9","status":"' . "\xc3\xa9" . '","webhook_type":"z"}', "1760702400:\xc3\xa9:\xc3\xa9:z"],
        ];
    }

    #[DataProvider('simpleMessages')]
    public function testSimpleMessageIsBuiltLikePythonStr(string $body, string $message): void
    {
        $event = Webhook::verify($body, ['X-Signature-Simple' => self::sign($message)], self::SECRET, allowSimple: true, now: self::NOW);

        self::assertSame('X-Signature-Simple', $event->verifiedWith());
    }

    public function testSimpleCannotVerifyObjectFields(): void
    {
        $body = '{"timestamp":1760702400,"session_id":{"a":1},"status":"Approved","webhook_type":"status.updated"}';
        self::assertRejected(
            WebhookVerificationException::INVALID_SIGNATURE,
            static fn () => Webhook::verify($body, ['X-Signature-Simple' => self::sign("1760702400:{'a': 1}:Approved:status.updated")], self::SECRET, allowSimple: true, now: self::NOW),
        );
    }

    public function testV2SurvivesReEncodingByMiddleware(): void
    {
        // What Didit sends: ASCII escapes. What a middleware may hand over: pretty-printed literal UTF-8.
        $sent = '{"session_id":"s","status":"Approved","timestamp":1760702400,"vendor_data":"Jos\\u00e9 \\ud83d\\ude00","webhook_type":"status.updated"}';
        $canonical = '{"session_id":"s","status":"Approved","timestamp":1760702400,"vendor_data":"Jos' . "\xc3\xa9 \xf0\x9f\x98\x80" . '","webhook_type":"status.updated"}';
        $reEncoded = json_encode(json_decode($sent), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE);
        self::assertIsString($reEncoded);
        $headers = ['X-Signature' => self::sign($sent), 'X-Signature-V2' => self::sign($canonical), 'X-Timestamp' => (string) self::TIMESTAMP];

        self::assertSame('X-Signature', Webhook::verify($sent, $headers, self::SECRET, now: self::NOW)->verifiedWith());
        $event = Webhook::verify($reEncoded, $headers, self::SECRET, now: self::NOW);
        self::assertSame('X-Signature-V2', $event->verifiedWith());
        self::assertTrue($event->bodyAuthenticated());
        self::assertSame("Jos\xc3\xa9 \xf0\x9f\x98\x80", $event->get('vendor_data'));
    }

    public function testIntegersAboveTheDoubleRangeSurviveTheRawBody(): void
    {
        $body = '{"amount":123456789012345678901234567890,"id":9007199254740993,"timestamp":1760702400,"webhook_type":"x.unknown"}';
        $headers = ['X-Signature-V2' => self::sign($body)];

        $event = Webhook::verify($body, $headers, self::SECRET, now: self::NOW);
        self::assertSame('X-Signature-V2', $event->verifiedWith());
        // Like json_decode() with JSON_BIGINT_AS_STRING, the rest of the SDK's convention.
        self::assertSame('123456789012345678901234567890', $event->get('amount'));
        self::assertSame(9007199254740993, $event->get('id'));

        // json_decode() turns the big integer into a float, so the parsed payload no longer matches.
        self::assertRejected(
            WebhookVerificationException::INVALID_SIGNATURE,
            static fn () => Webhook::verifyParsed((array) json_decode($body, true), $headers, self::SECRET, now: self::NOW),
        );
    }

    public function testVerifyParsedChecksV2(): void
    {
        $headers = ['x-signature-v2' => self::sign(self::BODY), 'X-Timestamp' => (string) self::TIMESTAMP];

        $fromArray = Webhook::verifyParsed(self::payload(), $headers, self::SECRET, now: self::NOW);
        self::assertInstanceOf(StatusUpdatedEvent::class, $fromArray);
        self::assertSame('X-Signature-V2', $fromArray->verifiedWith());
        self::assertTrue($fromArray->bodyAuthenticated());

        $object = json_decode(self::BODY);
        self::assertInstanceOf(\stdClass::class, $object);
        $fromObject = Webhook::verifyParsed($object, $headers, self::SECRET, now: self::NOW);
        self::assertSame(self::payload(), $fromObject->toArray());
    }

    public function testVerifyParsedCannotCheckTheRawOrSimpleSignatures(): void
    {
        $headers = [
            'X-Signature' => self::sign(self::BODY),
            'X-Signature-Simple' => self::sign('1760702400:sess-1:Approved:status.updated'),
        ];
        try {
            Webhook::verifyParsed(self::payload(), $headers, self::SECRET, now: self::NOW);
            self::fail('verifyParsed() must not accept X-Signature');
        } catch (WebhookVerificationException $exception) {
            self::assertSame(WebhookVerificationException::INVALID_SIGNATURE, $exception->reason);
            self::assertStringContainsString('verify()', $exception->getMessage());
        }
        self::assertRejected(WebhookVerificationException::MISSING_SIGNATURE, static fn () => Webhook::verifyParsed(self::payload(), [], self::SECRET, now: self::NOW));
    }

    public function testVerifyParsedTellsEmptyObjectsOnlyFromStdClass(): void
    {
        $body = '{"metadata":{},"timestamp":1760702400,"webhook_type":"status.updated"}';
        $headers = ['X-Signature-V2' => self::sign($body)];
        $object = json_decode($body);
        self::assertInstanceOf(\stdClass::class, $object);

        self::assertSame('X-Signature-V2', Webhook::verifyParsed($object, $headers, self::SECRET, now: self::NOW)->verifiedWith());
        self::assertRejected(
            WebhookVerificationException::INVALID_SIGNATURE,
            static fn () => Webhook::verifyParsed((array) json_decode($body, true), $headers, self::SECRET, now: self::NOW),
        );
    }

    public function testVerifyParsedRejectsWhatIsNotAJsonObject(): void
    {
        $headers = self::headers();
        self::assertRejected(WebhookVerificationException::INVALID_PAYLOAD, static fn () => Webhook::verifyParsed([1, 2], $headers, self::SECRET, now: self::NOW));
        self::assertRejected(WebhookVerificationException::INVALID_PAYLOAD, static fn () => Webhook::verifyParsed(new \ArrayObject(self::payload()), $headers, self::SECRET, now: self::NOW));
        self::assertRejected(WebhookVerificationException::MISSING_TIMESTAMP, static fn () => Webhook::verifyParsed([], $headers, self::SECRET, now: self::NOW));
        self::assertRejected(WebhookVerificationException::MISSING_TIMESTAMP, static fn () => Webhook::verifyParsed(['timestamp' => '1760702400'], $headers, self::SECRET, now: self::NOW));
        self::assertRejected(
            WebhookVerificationException::INVALID_PAYLOAD,
            static fn () => Webhook::verifyParsed(['timestamp' => self::TIMESTAMP, 'when' => new \DateTimeImmutable()], ['X-Signature-V2' => self::sign(self::BODY)], self::SECRET, now: self::NOW),
        );
    }

    public function testVerifyParsedChecksTheTimestamp(): void
    {
        $headers = self::headers();
        self::assertRejected(WebhookVerificationException::TIMESTAMP_OUT_OF_TOLERANCE, static fn () => Webhook::verifyParsed(self::payload(), $headers, self::SECRET, now: self::NOW + 1000));
        self::assertRejected(WebhookVerificationException::TIMESTAMP_MISMATCH, static fn () => Webhook::verifyParsed(self::payload(), ['X-Timestamp' => '5'] + $headers, self::SECRET, now: self::NOW));
    }

    public function testTheTraitDelegatesToWebhook(): void
    {
        $resource = new class {
            use VerifiesWebhooks;
        };

        $event = $resource->verify(self::BODY, self::headers(), self::SECRET, now: self::NOW);
        self::assertSame('X-Signature', $event->verifiedWith());

        $parsed = $resource->verifyParsed(self::payload(), self::headers(), self::SECRET, now: self::NOW);
        self::assertSame('X-Signature-V2', $parsed->verifiedWith());

        $simple = $resource->verify(self::BODY, ['X-Signature-Simple' => self::sign('1760702400:sess-1:Approved:status.updated')], self::SECRET, 300, true, self::NOW);
        self::assertFalse($simple->bodyAuthenticated());

        self::assertRejected(WebhookVerificationException::TIMESTAMP_OUT_OF_TOLERANCE, static fn () => $resource->verify(self::BODY, self::headers(), self::SECRET, 5, now: self::NOW));
    }

    public function testEventsBuiltWithoutVerificationSayso(): void
    {
        $event = Event::fromPayload(self::payload());

        self::assertNull($event->verifiedWith());
        self::assertFalse($event->bodyAuthenticated());
    }

    /**
     * @return array<string, string>
     */
    private static function headers(): array
    {
        return [
            'X-Signature' => self::sign(self::BODY),
            'X-Signature-V2' => self::sign(self::BODY),
            'X-Signature-Simple' => self::sign('1760702400:sess-1:Approved:status.updated'),
            'X-Timestamp' => (string) self::TIMESTAMP,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function payload(): array
    {
        $payload = json_decode(self::BODY, true);
        self::assertIsArray($payload);

        /** @var array<string, mixed> $payload */
        return $payload;
    }

    private static function sign(string $message, string $secret = self::SECRET): string
    {
        return hash_hmac('sha256', $message, $secret);
    }

    /**
     * @param callable(): mixed $call
     */
    private static function assertRejected(string $reason, callable $call): void
    {
        try {
            $call();
        } catch (WebhookVerificationException $exception) {
            self::assertSame($reason, $exception->reason, $exception->getMessage());

            return;
        }
        self::fail('Expected a WebhookVerificationException with reason ' . $reason);
    }
}
