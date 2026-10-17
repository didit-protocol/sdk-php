<?php

declare(strict_types=1);

namespace Didit\Webhooks;

use Didit\Exception\ConfigurationException;
use Didit\Exception\WebhookVerificationException;
use Psr\Http\Message\MessageInterface;

/**
 * Verifies Didit webhook deliveries (DESIGN.md section 9) and returns them as
 * typed events. No client, network or framework is needed.
 *
 * ```php
 * $event = Webhook::verify($rawBody, getallheaders(), $secret);
 * if ($event instanceof Events\StatusUpdatedEvent && 'Approved' === $event->status) {
 *     // ...
 * }
 * ```
 *
 * Rules, in order:
 * 1. The body must be a JSON object with an integer `timestamp`; when
 *    `X-Timestamp` is present it must be equal.
 * 2. The timestamp must be within `$tolerance` seconds of now.
 * 3. One of `X-Signature` (HMAC-SHA256 of the raw body), `X-Signature-V2`
 *    (HMAC-SHA256 of the canonical JSON, {@see CanonicalJson}) or their
 *    `-Previous` variants must match one of the secrets.
 * 4. `X-Signature-Simple` and `X-Signature-Simple-Previous` count only with
 *    `$allowSimple`. They sign `timestamp:session_id:status:webhook_type`
 *    and nothing else, so the event then reports
 *    {@see Event::bodyAuthenticated()} false: re-fetch the record from the API
 *    before acting on any other field.
 *
 * Signatures are compared in constant time, and only values made of exactly
 * 64 lowercase hex characters are compared at all.
 */
final class Webhook
{
    public const HEADER_SIGNATURE = 'X-Signature';

    public const HEADER_SIGNATURE_V2 = 'X-Signature-V2';

    public const HEADER_SIGNATURE_SIMPLE = 'X-Signature-Simple';

    public const HEADER_SIGNATURE_PREVIOUS = 'X-Signature-Previous';

    public const HEADER_SIGNATURE_V2_PREVIOUS = 'X-Signature-V2-Previous';

    public const HEADER_SIGNATURE_SIMPLE_PREVIOUS = 'X-Signature-Simple-Previous';

    public const HEADER_TIMESTAMP = 'X-Timestamp';

    /** Default maximum distance in seconds between the delivery's timestamp and now. */
    public const DEFAULT_TOLERANCE = 300;

    /** Every signature header, in the order matches are reported. */
    private const SIGNATURE_HEADERS = [
        self::HEADER_SIGNATURE,
        self::HEADER_SIGNATURE_V2,
        self::HEADER_SIGNATURE_PREVIOUS,
        self::HEADER_SIGNATURE_V2_PREVIOUS,
        self::HEADER_SIGNATURE_SIMPLE,
        self::HEADER_SIGNATURE_SIMPLE_PREVIOUS,
    ];

    /** The fields `X-Signature-Simple` signs, in order. */
    private const SIMPLE_FIELDS = ['timestamp', 'session_id', 'status', 'webhook_type'];

    private function __construct()
    {
    }

    /**
     * Verifies a delivery from its raw body and returns the typed event.
     *
     * @param string                                             $rawBody     the request body exactly as received
     * @param array<string, string|list<string>>|MessageInterface $headers     the request headers, names in any case:
     *                                                                        `getallheaders()`, Laravel or Symfony
     *                                                                        `$request->headers->all()`, or any PSR-7
     *                                                                        request
     * @param string|list<string>                                $secret      the destination's secret, or several during
     *                                                                        a rotation
     * @param int                                                $tolerance   maximum distance in seconds between the
     *                                                                        delivery's timestamp and now
     * @param bool                                               $allowSimple also accept the deprecated
     *                                                                        `X-Signature-Simple`, which does not cover
     *                                                                        the body
     * @param int|null                                           $now         current unix time, for tests
     *
     * @throws WebhookVerificationException when the delivery fails verification; `reason` says why
     * @throws ConfigurationException       when the secret is empty or the tolerance negative
     */
    public static function verify(
        string $rawBody,
        array|MessageInterface $headers,
        string|array $secret,
        int $tolerance = self::DEFAULT_TOLERANCE,
        bool $allowSimple = false,
        ?int $now = null,
    ): Event {
        $secrets = self::secrets($secret);
        self::checkTolerance($tolerance);
        [$canonical, $payload] = CanonicalJson::parse($rawBody);
        if (!str_starts_with($canonical, '{') || !\is_array($payload)) {
            throw new WebhookVerificationException(
                WebhookVerificationException::INVALID_PAYLOAD,
                'The webhook body is not a JSON object.',
            );
        }
        /** @var array<string, mixed> $payload */
        $headerValues = self::normalizeHeaders($headers);
        self::checkTimestamp($payload, $headerValues, $tolerance, $now);

        $signed = [
            self::HEADER_SIGNATURE => $rawBody,
            self::HEADER_SIGNATURE_V2 => $canonical,
            self::HEADER_SIGNATURE_PREVIOUS => $rawBody,
            self::HEADER_SIGNATURE_V2_PREVIOUS => $canonical,
        ];
        if ($allowSimple) {
            $simple = self::simpleMessage($payload);
            $signed[self::HEADER_SIGNATURE_SIMPLE] = $simple;
            $signed[self::HEADER_SIGNATURE_SIMPLE_PREVIOUS] = $simple;
        }
        $matched = self::findMatch($headerValues, $signed, $secrets);
        if (null === $matched) {
            throw self::noMatch($headerValues, $allowSimple, false);
        }

        return Event::fromVerifiedPayload($payload, $matched);
    }

    /**
     * Verifies a delivery from a body the framework already decoded, and
     * returns the typed event.
     *
     * Prefer {@see self::verify()} with the raw body whenever the framework
     * gives access to it. A decoded body can only be checked against
     * `X-Signature-V2` (and `X-Signature-V2-Previous`), and only when the
     * decoding kept the values intact: an array from `json_decode($body, true)`
     * cannot tell `{}` from `[]` (empty arrays are taken as `[]`; decode into
     * `stdClass` objects to keep the difference), and `json_decode` turns
     * integers above PHP_INT_MAX into floats or strings, so a payload holding
     * one no longer matches its signature. `X-Signature` and
     * `X-Signature-Simple` are never checked here.
     *
     * @param array<string, mixed>|object                        $payload   the decoded JSON object: an array from
     *                                                                      `json_decode($body, true)` or a
     *                                                                      `stdClass` from `json_decode($body)`
     * @param array<string, string|list<string>>|MessageInterface $headers   the request headers, names in any case
     * @param string|list<string>                                $secret    the destination's secret, or several
     *                                                                      during a rotation
     * @param int                                                $tolerance maximum distance in seconds between the
     *                                                                      delivery's timestamp and now
     * @param int|null                                           $now       current unix time, for tests
     *
     * @throws WebhookVerificationException when the delivery fails verification; `reason` says why
     * @throws ConfigurationException       when the secret is empty or the tolerance negative
     */
    public static function verifyParsed(
        array|object $payload,
        array|MessageInterface $headers,
        string|array $secret,
        int $tolerance = self::DEFAULT_TOLERANCE,
        ?int $now = null,
    ): Event {
        $secrets = self::secrets($secret);
        self::checkTolerance($tolerance);
        if (\is_object($payload) && !$payload instanceof \stdClass) {
            throw new WebhookVerificationException(
                WebhookVerificationException::INVALID_PAYLOAD,
                \sprintf('The payload must be an array or a stdClass object, %s given.', get_debug_type($payload)),
            );
        }
        if (\is_array($payload) && [] !== $payload && array_is_list($payload)) {
            throw new WebhookVerificationException(
                WebhookVerificationException::INVALID_PAYLOAD,
                'The payload is a JSON array, not a JSON object.',
            );
        }
        $data = self::toArrays($payload);
        /** @var array<string, mixed> $data */
        $headerValues = self::normalizeHeaders($headers);
        self::checkTimestamp($data, $headerValues, $tolerance, $now);

        $canonical = CanonicalJson::encode(\is_array($payload) ? (object) $payload : $payload);
        $matched = self::findMatch($headerValues, [
            self::HEADER_SIGNATURE_V2 => $canonical,
            self::HEADER_SIGNATURE_V2_PREVIOUS => $canonical,
        ], $secrets);
        if (null === $matched) {
            throw self::noMatch($headerValues, false, true);
        }

        return Event::fromVerifiedPayload($data, $matched);
    }

    /**
     * @param string|array<mixed> $secret
     *
     * @return list<string>
     */
    private static function secrets(string|array $secret): array
    {
        $secrets = \is_string($secret) ? [$secret] : array_values($secret);
        if ([] === $secrets) {
            throw new ConfigurationException('No webhook secret given: pass the destination\'s secret, or a list of secrets during a rotation.');
        }
        $out = [];
        foreach ($secrets as $value) {
            if (!\is_string($value) || '' === $value) {
                throw new ConfigurationException('A webhook secret is empty or not a string: pass the destination\'s secret from the Didit Console or the webhooks API.');
            }
            $out[] = $value;
        }

        return $out;
    }

    private static function checkTolerance(int $tolerance): void
    {
        if ($tolerance < 0) {
            throw new ConfigurationException('The webhook timestamp tolerance must be zero or more seconds.');
        }
    }

    /**
     * @param array<string, mixed>        $payload
     * @param array<string, list<string>> $headers
     */
    private static function checkTimestamp(array $payload, array $headers, int $tolerance, ?int $now): void
    {
        $timestamp = $payload['timestamp'] ?? null;
        if (!\is_int($timestamp)) {
            throw new WebhookVerificationException(
                WebhookVerificationException::MISSING_TIMESTAMP,
                'The webhook body has no integer "timestamp".',
            );
        }
        $header = self::headerValues($headers, self::HEADER_TIMESTAMP)[0] ?? null;
        if (null !== $header && (string) $timestamp !== $header) {
            throw new WebhookVerificationException(
                WebhookVerificationException::TIMESTAMP_MISMATCH,
                'The X-Timestamp header differs from the body\'s "timestamp".',
            );
        }
        $now ??= time();
        if (abs($now - $timestamp) > $tolerance) {
            throw new WebhookVerificationException(
                WebhookVerificationException::TIMESTAMP_OUT_OF_TOLERANCE,
                \sprintf('The webhook timestamp is %d seconds away from now, more than the %d seconds allowed.', abs($now - $timestamp), $tolerance),
            );
        }
    }

    /**
     * The first signature header (in {@see self::SIGNATURE_HEADERS} order)
     * whose value is the HMAC of its signed message under one of the secrets.
     *
     * @param array<string, list<string>> $headers
     * @param array<string, string|null>  $signed  header name => signed message (null when it cannot be built)
     * @param list<string>                $secrets
     */
    private static function findMatch(array $headers, array $signed, array $secrets): ?string
    {
        // A header and its -Previous variant sign the same message: compute each HMAC once.
        /** @var array<string, string> $expected */
        $expected = [];
        foreach (self::SIGNATURE_HEADERS as $name) {
            $message = $signed[$name] ?? null;
            if (null === $message) {
                continue;
            }
            $candidates = self::signatureCandidates($headers, $name);
            if ([] === $candidates) {
                continue;
            }
            foreach ($secrets as $index => $secret) {
                $cacheKey = str_replace('-Previous', '', $name) . ':' . $index;
                $hmac = $expected[$cacheKey] ??= hash_hmac('sha256', $message, $secret);
                foreach ($candidates as $candidate) {
                    if (hash_equals($hmac, $candidate)) {
                        return $name;
                    }
                }
            }
        }

        return null;
    }

    /**
     * The well-formed values of a signature header: 64 lowercase hex
     * characters each. Anything else can never match and is not compared.
     *
     * @param array<string, list<string>> $headers
     *
     * @return list<string>
     */
    private static function signatureCandidates(array $headers, string $name): array
    {
        $candidates = [];
        foreach (self::headerValues($headers, $name) as $value) {
            foreach (explode(',', $value) as $part) {
                $part = trim($part, " \t");
                if (1 === preg_match('/^[0-9a-f]{64}$/D', $part)) {
                    $candidates[] = $part;
                }
            }
        }

        return $candidates;
    }

    /**
     * @param array<string, list<string>> $headers
     */
    private static function noMatch(array $headers, bool $allowSimple, bool $parsed): WebhookVerificationException
    {
        $present = array_values(array_filter(
            self::SIGNATURE_HEADERS,
            static fn (string $name): bool => [] !== self::headerValues($headers, $name),
        ));
        if ([] === $present) {
            return new WebhookVerificationException(
                WebhookVerificationException::MISSING_SIGNATURE,
                'The request has no Didit signature header (X-Signature, X-Signature-V2 or X-Signature-Simple).',
            );
        }
        $checked = $parsed
            ? [self::HEADER_SIGNATURE_V2, self::HEADER_SIGNATURE_V2_PREVIOUS]
            : ($allowSimple ? self::SIGNATURE_HEADERS : array_slice(self::SIGNATURE_HEADERS, 0, 4));
        if ([] === array_intersect($present, $checked)) {
            $message = $parsed
                ? 'verifyParsed() can check only X-Signature-V2, and the request has none; pass the raw body to verify() instead.'
                : 'The request carries only X-Signature-Simple, which does not cover the body and counts only with allowSimple: true.';

            return new WebhookVerificationException(WebhookVerificationException::INVALID_SIGNATURE, $message);
        }

        return new WebhookVerificationException(
            WebhookVerificationException::INVALID_SIGNATURE,
            'No webhook signature matches the secret: check that the secret belongs to this destination and that the body reaches verify() unmodified.',
        );
    }

    /**
     * The message `X-Signature-Simple` signs, built like the backend's
     * `":".join(str(data.get(k, "")) for k in fields)`: a missing field is an
     * empty string, `null` is `None` and booleans are `True` / `False`. Null
     * when a field is an array or object, whose Python `str()` is not
     * reproduced: such a delivery cannot be verified with this header.
     *
     * @param array<string, mixed> $payload
     */
    private static function simpleMessage(array $payload): ?string
    {
        $parts = [];
        foreach (self::SIMPLE_FIELDS as $field) {
            if (!\array_key_exists($field, $payload)) {
                $parts[] = '';
                continue;
            }
            $value = $payload[$field];
            $part = match (true) {
                null === $value => 'None',
                true === $value => 'True',
                false === $value => 'False',
                \is_string($value) => $value,
                \is_int($value) => (string) $value,
                \is_float($value) => CanonicalJson::pythonRepr($value),
                default => null,
            };
            if (null === $part) {
                return null;
            }
            $parts[] = $part;
        }

        return implode(':', $parts);
    }

    /**
     * Header values by lower-case name, from an array in any common shape or a
     * PSR-7 message.
     *
     * @param array<mixed>|MessageInterface $headers
     *
     * @return array<string, list<string>>
     */
    private static function normalizeHeaders(array|MessageInterface $headers): array
    {
        $source = $headers instanceof MessageInterface ? $headers->getHeaders() : $headers;
        $out = [];
        foreach ($source as $name => $values) {
            $key = self::lowerAscii(trim((string) $name));
            foreach (\is_array($values) ? $values : [$values] as $value) {
                if (\is_int($value)) {
                    $value = (string) $value;
                }
                if (\is_string($value)) {
                    $out[$key][] = $value;
                }
            }
        }

        return $out;
    }

    /**
     * The non-empty, trimmed values of a header.
     *
     * @param array<string, list<string>> $headers
     *
     * @return list<string>
     */
    private static function headerValues(array $headers, string $name): array
    {
        $values = [];
        foreach ($headers[self::lowerAscii($name)] ?? [] as $value) {
            $value = trim($value, " \t");
            if ('' !== $value) {
                $values[] = $value;
            }
        }

        return $values;
    }

    /**
     * Lower-cases ASCII letters only, whatever the locale.
     */
    private static function lowerAscii(string $value): string
    {
        return strtr($value, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz');
    }

    /**
     * Turns `stdClass` objects into associative arrays, recursively.
     */
    private static function toArrays(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $value = get_object_vars($value);
        }
        if (\is_array($value)) {
            return array_map(self::toArrays(...), $value);
        }

        return $value;
    }
}
