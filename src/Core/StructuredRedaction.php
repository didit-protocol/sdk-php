<?php

declare(strict_types=1);

namespace Didit\Core;

/**
 * Redacts secrets from a parsed JSON value, never from raw text (docs/DESIGN.md section 10).
 *
 * Every string, object key or value, that contains a secret anywhere in it becomes
 * `[redacted]` as a whole. Strings are compared after JSON decoding, so the spelling of the
 * raw text (escaped slashes, upper-case escapes) does not matter. A redacted key that
 * collides with another key is numbered (`[redacted]`, `[redacted]2`, ...).
 *
 * @internal
 */
final class StructuredRedaction
{
    public const REDACTED = '[redacted]';

    /** Shorter assertion strings (`id`, `type`, `public-key`) are not secrets: matching them would erase codes such as `invalid_grant`. */
    // Sensitive values are 16+ chars: user handles are 16-byte ids (22 base64url), credential ids 16+ bytes.
    private const MIN_SECRET_LENGTH = 16;

    private function __construct()
    {
    }

    /**
     * The body to keep: the redacted JSON when it parses, `[redacted]` when it does not
     * (an HTML error page, a body cut at the size limit), the empty string when it is empty.
     *
     * @param list<string> $secrets
     */
    public static function body(string $raw, #[\SensitiveParameter] array $secrets): string
    {
        if ('' === trim($raw)) {
            return '';
        }
        try {
            $value = json_decode($raw, false, 512, \JSON_THROW_ON_ERROR | \JSON_BIGINT_AS_STRING);

            return json_encode(self::value($value, $secrets), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        } catch (\JsonException) {
            return self::REDACTED;
        }
    }

    /**
     * The secrets of a consent request: every string of at least 16 characters of its `assertion`, object keys and values
     * at any depth, read from the exact JSON bytes that were sent (never from a second serialization of the caller's
     * objects).
     *
     * @return list<string>
     */
    public static function assertionStrings(string $sentJson): array
    {
        $sent = json_decode($sentJson, false, 512, \JSON_THROW_ON_ERROR);

        return $sent instanceof \stdClass && property_exists($sent, 'assertion') ? self::walk($sent->assertion) : [];
    }

    /**
     * @return list<string>
     */
    private static function walk(mixed $value): array
    {
        if (\is_string($value)) {
            return \strlen($value) >= self::MIN_SECRET_LENGTH ? [$value] : [];
        }
        $found = [];
        if (\is_array($value)) {
            foreach ($value as $item) {
                array_push($found, ...self::walk($item));
            }
        } elseif ($value instanceof \stdClass) {
            foreach (get_object_vars($value) as $key => $item) {
                array_push($found, ...self::walk((string) $key));
                array_push($found, ...self::walk($item));
            }
        }

        return $found;
    }

    /**
     * @param list<string> $secrets
     */
    private static function value(mixed $value, array $secrets): mixed
    {
        if (\is_string($value)) {
            return self::text($value, $secrets);
        }
        if (\is_array($value)) {
            return array_map(static fn (mixed $item): mixed => self::value($item, $secrets), $value);
        }
        if ($value instanceof \stdClass) {
            $result = [];
            foreach (get_object_vars($value) as $key => $item) {
                $name = self::text((string) $key, $secrets);
                for ($number = 2; \array_key_exists($name, $result); ++$number) {
                    $name = self::REDACTED . $number;
                }
                $result[$name] = self::value($item, $secrets);
            }

            return (object) $result;
        }

        return $value;
    }

    /**
     * @param list<string> $secrets
     */
    private static function text(string $text, array $secrets): string
    {
        foreach ($secrets as $secret) {
            if ('' !== $secret && str_contains($text, $secret)) {
                return self::REDACTED;
            }
        }

        return $text;
    }
}
