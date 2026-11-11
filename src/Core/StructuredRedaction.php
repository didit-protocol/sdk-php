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
     * The secrets of a JSON request value: every string of at least 16 characters, object keys and values at any depth,
     * read from the exact JSON the request carries (encoded with `$flags`, then decoded back), so whatever objects the
     * caller nested in it (`stdClass`, `JsonSerializable`) the set equals what goes over the wire.
     *
     * @return list<string>
     */
    public static function wireStrings(mixed $value, int $flags): array
    {
        return self::walk(json_decode(json_encode($value, $flags | \JSON_THROW_ON_ERROR), false, 512, \JSON_THROW_ON_ERROR));
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
