<?php

declare(strict_types=1);

namespace Didit\Tests\Webhooks;

use Didit\Exception\WebhookVerificationException;
use Didit\Webhooks\CanonicalJson;
use Didit\Webhooks\Webhook;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Runs every vector of `openapi/webhook-vectors.json`, which the backend's own
 * signing code produced (DESIGN.md section 9.3).
 */
final class WebhookVectorsTest extends TestCase
{
    /**
     * @return array<string, array{array{name: string, secret: string, previous_secret: string|null, body: string, canonical_v2: string, headers: array<string, string>, now: int, expect: string}}>
     */
    public static function vectors(): array
    {
        $cases = [];
        foreach (self::document()['vectors'] as $vector) {
            $cases[$vector['name']] = [$vector];
        }

        return $cases;
    }

    /**
     * The vectors a receiver must accept.
     *
     * @return array<string, array{array{name: string, secret: string, previous_secret: string|null, body: string, canonical_v2: string, headers: array<string, string>, now: int, expect: string}}>
     */
    public static function validVectors(): array
    {
        return array_filter(self::vectors(), static fn (array $case): bool => 'valid' === $case[0]['expect']);
    }

    /**
     * The vectors signed during a secret rotation (current and previous secret).
     *
     * @return array<string, array{array{name: string, secret: string, previous_secret: string, body: string, canonical_v2: string, headers: array<string, string>, now: int, expect: string}}>
     */
    public static function rotationVectors(): array
    {
        $cases = [];
        foreach (self::vectors() as $name => [$vector]) {
            if (null !== $vector['previous_secret']) {
                $cases[$name] = [['previous_secret' => $vector['previous_secret']] + $vector];
            }
        }

        return $cases;
    }

    public function testTheVectorFileCoversEveryOutcome(): void
    {
        $document = self::document();
        self::assertSame(300, $document['tolerance_seconds']);
        self::assertCount(25, $document['vectors']);
        $outcomes = array_unique(array_column($document['vectors'], 'expect'));
        sort($outcomes);
        self::assertSame([
            'invalid_signature',
            'missing_signature',
            'timestamp_mismatch',
            'timestamp_out_of_tolerance',
            'valid',
            'valid_only_with_allow_simple',
        ], $outcomes);
    }

    /**
     * @param array{name: string, secret: string, previous_secret: string|null, body: string, canonical_v2: string, headers: array<string, string>, now: int, expect: string} $vector
     */
    #[DataProvider('vectors')]
    public function testCanonicalJsonMatchesTheBackendByteForByte(array $vector): void
    {
        $canonical = CanonicalJson::canonicalize($vector['body']);
        if ('tampered_body' === $vector['name']) {
            // The body was altered after signing ("Approved" became "Declined"),
            // so canonical_v2 is the string the backend signed before the
            // tampering and must not equal what the delivered body yields.
            self::assertNotSame($vector['canonical_v2'], $canonical);
            self::assertSame(str_replace('"Approved"', '"Declined"', $vector['canonical_v2']), $canonical);

            return;
        }
        self::assertSame($vector['canonical_v2'], $canonical);
        self::assertSame($vector['canonical_v2'], CanonicalJson::encode(json_decode($vector['body'])));
    }

    /**
     * @param array{name: string, secret: string, previous_secret: string|null, body: string, canonical_v2: string, headers: array<string, string>, now: int, expect: string} $vector
     */
    #[DataProvider('vectors')]
    public function testVerifyGivesTheExpectedOutcome(array $vector): void
    {
        $verify = static fn (bool $allowSimple) => Webhook::verify(
            $vector['body'],
            $vector['headers'],
            $vector['secret'],
            self::document()['tolerance_seconds'],
            $allowSimple,
            $vector['now'],
        );

        switch ($vector['expect']) {
            case 'valid':
                $event = $verify(false);
                self::assertTrue($event->bodyAuthenticated());
                self::assertSame(json_decode($vector['body'], true), $event->toArray());
                // The receiver that still holds only the previous secret matches the -Previous header.
                $expected = 'rotation_receiver_still_on_previous_secret' === $vector['name'] ? 'X-Signature-Previous' : 'X-Signature';
                self::assertSame($expected, $event->verifiedWith());
                break;
            case 'valid_only_with_allow_simple':
                self::assertRejected(WebhookVerificationException::INVALID_SIGNATURE, static fn () => $verify(false));
                $event = $verify(true);
                self::assertSame('X-Signature-Simple', $event->verifiedWith());
                self::assertFalse($event->bodyAuthenticated());
                break;
            default:
                self::assertRejected($vector['expect'], static fn () => $verify(false));
        }
    }

    /**
     * @param array{name: string, secret: string, previous_secret: string|null, body: string, canonical_v2: string, headers: array<string, string>, now: int, expect: string} $vector
     */
    #[DataProvider('validVectors')]
    public function testEachSignatureHeaderVerifiesOnItsOwn(array $vector): void
    {
        $previous = null !== $vector['previous_secret'] || 'rotation_receiver_still_on_previous_secret' === $vector['name'];
        $names = $previous
            ? ['X-Signature-Previous', 'X-Signature-V2-Previous', 'X-Signature-Simple-Previous']
            : ['X-Signature', 'X-Signature-V2', 'X-Signature-Simple'];
        $secret = null !== $vector['previous_secret'] ? $vector['previous_secret'] : $vector['secret'];
        foreach ($names as $name) {
            $headers = ['X-Timestamp' => $vector['headers']['X-Timestamp'], $name => $vector['headers'][$name]];
            $allowSimple = str_contains($name, 'Simple');
            $event = Webhook::verify($vector['body'], $headers, $secret, 300, $allowSimple, $vector['now']);
            self::assertSame($name, $event->verifiedWith());
            self::assertSame(!$allowSimple, $event->bodyAuthenticated());
        }
    }

    /**
     * @param array{name: string, secret: string, previous_secret: string, body: string, canonical_v2: string, headers: array<string, string>, now: int, expect: string} $vector
     */
    #[DataProvider('rotationVectors')]
    public function testRotationVectorsVerifyWithEitherSecretOrBoth(array $vector): void
    {
        $current = Webhook::verify($vector['body'], $vector['headers'], $vector['secret'], now: $vector['now']);
        self::assertSame('X-Signature', $current->verifiedWith());

        $previous = Webhook::verify($vector['body'], $vector['headers'], $vector['previous_secret'], now: $vector['now']);
        self::assertSame('X-Signature-Previous', $previous->verifiedWith());

        $both = Webhook::verify($vector['body'], $vector['headers'], ['whsec_unrelated', $vector['previous_secret'], $vector['secret']], now: $vector['now']);
        self::assertSame('X-Signature', $both->verifiedWith());

        self::assertRejected(
            WebhookVerificationException::INVALID_SIGNATURE,
            static fn () => Webhook::verify($vector['body'], $vector['headers'], 'whsec_unrelated', now: $vector['now']),
        );
    }

    /**
     * verifyParsed() checks only X-Signature-V2, so the Simple-only vector
     * fails even though verify() accepts it with allowSimple.
     *
     * @param array{name: string, secret: string, previous_secret: string|null, body: string, canonical_v2: string, headers: array<string, string>, now: int, expect: string} $vector
     */
    #[DataProvider('vectors')]
    public function testVerifyParsedGivesTheExpectedOutcome(array $vector): void
    {
        $expect = 'valid_only_with_allow_simple' === $vector['expect'] ? WebhookVerificationException::INVALID_SIGNATURE : $vector['expect'];
        $decoded = [
            'array' => json_decode($vector['body'], true),
            'stdClass' => json_decode($vector['body']),
        ];
        foreach ($decoded as $payload) {
            self::assertTrue(\is_array($payload) || $payload instanceof \stdClass);
            $verify = static fn () => Webhook::verifyParsed($payload, $vector['headers'], $vector['secret'], 300, $vector['now']);
            if ('valid' !== $expect) {
                self::assertRejected($expect, $verify);
                continue;
            }
            $event = $verify();
            $expected = 'rotation_receiver_still_on_previous_secret' === $vector['name'] ? 'X-Signature-V2-Previous' : 'X-Signature-V2';
            self::assertSame($expected, $event->verifiedWith());
            self::assertTrue($event->bodyAuthenticated());
            self::assertSame(json_decode($vector['body'], true), $event->toArray());
        }
    }

    /**
     * @param callable(): mixed $call
     */
    private static function assertRejected(string $reason, callable $call): void
    {
        try {
            $call();
        } catch (WebhookVerificationException $exception) {
            self::assertSame($reason, $exception->reason);

            return;
        }
        self::fail('Expected a WebhookVerificationException with reason ' . $reason);
    }

    /**
     * @return array{tolerance_seconds: int, vectors: list<array{name: string, secret: string, previous_secret: string|null, body: string, canonical_v2: string, headers: array<string, string>, now: int, expect: string}>}
     */
    private static function document(): array
    {
        $json = file_get_contents(__DIR__ . '/../../openapi/webhook-vectors.json');
        self::assertIsString($json);
        $document = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($document);

        /** @var array{tolerance_seconds: int, vectors: list<array{name: string, secret: string, previous_secret: string|null, body: string, canonical_v2: string, headers: array<string, string>, now: int, expect: string}>} $document */
        return $document;
    }
}
