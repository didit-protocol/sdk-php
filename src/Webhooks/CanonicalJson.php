<?php

declare(strict_types=1);

namespace Didit\Webhooks;

use Didit\Exception\WebhookVerificationException;

/**
 * The canonical JSON form that `X-Signature-V2` signs (DESIGN.md section 9.2).
 *
 * It is what Python's `json.dumps(shorten_floats(value), sort_keys=True,
 * separators=(",", ":"), ensure_ascii=False)` produces: keys sorted by code
 * point, no whitespace, only `"`, `\` and control characters escaped, floats
 * with an integral value written as integers and other floats in Python
 * `repr` form.
 *
 * {@see self::canonicalize()} reads JSON text with its own lossless parser, so
 * integers of any size keep their exact digits. {@see self::encode()} takes
 * a value PHP already decoded, which is only as exact as that value.
 */
final class CanonicalJson
{
    /** Deepest nesting of arrays and objects accepted, like `json_decode`. */
    private const MAX_DEPTH = 512;

    /** Bytes that end a run of literal characters inside a JSON string. */
    private const STRING_STOP = "\"\\\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0a\x0b\x0c\x0d\x0e\x0f"
        . "\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1a\x1b\x1c\x1d\x1e\x1f";

    /** Two to the power of 63, the first float that no longer fits a PHP int. */
    private const TWO_POW_63 = 9.2233720368547758E18;

    /** @var array<string, string>|null */
    private static ?array $escapes = null;

    private readonly int $length;

    private int $pos = 0;

    private int $depth = 0;

    private function __construct(private readonly string $json)
    {
        $this->length = \strlen($json);
    }

    /**
     * Canonical form of JSON text.
     *
     * @throws WebhookVerificationException reason `invalid_payload` when the
     *                                      text is not valid JSON
     */
    public static function canonicalize(string $json): string
    {
        return self::parse($json)[0];
    }

    /**
     * Canonical form of a value PHP already decoded: arrays from
     * `json_decode($json, true)`, `stdClass` objects from `json_decode($json)`
     * and scalars.
     *
     * A list (including `[]`) is written as a JSON array and any other array
     * as an object, so an empty object decoded into an array comes back as
     * `[]`; decode into `stdClass` to keep `{}`. Integers that `json_decode`
     * turned into floats or strings (above PHP_INT_MAX) no longer have their
     * original digits; {@see self::canonicalize()} on the raw text has neither
     * limit.
     *
     * @throws WebhookVerificationException reason `invalid_payload` for values
     *                                      JSON cannot hold
     */
    public static function encode(mixed $value): string
    {
        return self::encodeValue($value, 0);
    }

    /**
     * Parses JSON text once into its canonical form and the value
     * `json_decode($json, true, 512, JSON_BIGINT_AS_STRING)` would return.
     *
     * @internal used by {@see Webhook}
     *
     * @return array{0: string, 1: mixed}
     *
     * @throws WebhookVerificationException reason `invalid_payload`
     */
    public static function parse(string $json): array
    {
        if (1 !== preg_match('//u', $json)) {
            throw self::invalid('The webhook body is not valid UTF-8.');
        }
        $parser = new self($json);
        $parser->skipWhitespace();
        $result = $parser->parseValue();
        $parser->skipWhitespace();
        if ($parser->pos !== $parser->length) {
            throw $parser->syntaxError('unexpected data after the JSON value');
        }

        return $result;
    }

    /**
     * A float as `shorten_floats` and `json.dumps` write it: an integral value
     * as the exact integer, anything else in Python `repr` form.
     *
     * @internal
     */
    public static function formatFloat(float $value): string
    {
        if (is_nan($value)) {
            return 'NaN';
        }
        if (is_infinite($value)) {
            return $value > 0 ? 'Infinity' : '-Infinity';
        }
        if (floor($value) === $value) {
            return self::integralFloatToString($value);
        }

        return self::pythonRepr($value);
    }

    /**
     * Python's `repr(value)` (and `str(value)`) of a float, for example `0.1`,
     * `1e-05`, `87.0`, `1e+16` or `-0.0`.
     *
     * @internal
     */
    public static function pythonRepr(float $value): string
    {
        if (is_nan($value)) {
            return 'nan';
        }
        if (is_infinite($value)) {
            return $value > 0 ? 'inf' : '-inf';
        }
        [$digits, $decimalPoint] = self::shortestDigits(abs($value));
        $count = \strlen($digits);
        if ($decimalPoint <= -4 || $decimalPoint > 16) {
            $exponent = $decimalPoint - 1;
            $text = $digits[0] . ($count > 1 ? '.' . substr($digits, 1) : '')
                . 'e' . ($exponent < 0 ? '-' : '+') . str_pad((string) abs($exponent), 2, '0', \STR_PAD_LEFT);
        } elseif ($decimalPoint <= 0) {
            $text = '0.' . str_repeat('0', -$decimalPoint) . $digits;
        } elseif ($decimalPoint >= $count) {
            $text = $digits . str_repeat('0', $decimalPoint - $count) . '.0';
        } else {
            $text = substr($digits, 0, $decimalPoint) . '.' . substr($digits, $decimalPoint);
        }

        return (self::signBit($value) ? '-' : '') . $text;
    }

    /**
     * @return array{0: string, 1: mixed}
     */
    private function parseValue(): array
    {
        if ($this->pos >= $this->length) {
            throw $this->syntaxError('unexpected end of input');
        }

        switch ($this->json[$this->pos]) {
            case '{':
                return $this->parseObject();
            case '[':
                return $this->parseArray();
            case '"':
                $string = $this->parseString();

                return [self::encodeString($string), $string];
            case 't':
                $this->expectLiteral('true');

                return ['true', true];
            case 'f':
                $this->expectLiteral('false');

                return ['false', false];
            case 'n':
                $this->expectLiteral('null');

                return ['null', null];
            default:
                return $this->parseNumber();
        }
    }

    /**
     * @return array{0: string, 1: array<array-key, mixed>}
     */
    private function parseObject(): array
    {
        $this->enter();
        ++$this->pos;
        $this->skipWhitespace();
        /** @var array<array-key, string> $canonical */
        $canonical = [];
        /** @var array<array-key, mixed> $value */
        $value = [];
        if ('}' === $this->peek()) {
            ++$this->pos;
            --$this->depth;

            return ['{}', []];
        }
        while (true) {
            if ('"' !== $this->peek()) {
                throw $this->syntaxError('expected a string key');
            }
            $key = $this->parseString();
            $this->skipWhitespace();
            $this->expect(':');
            $this->skipWhitespace();
            [$memberCanonical, $memberValue] = $this->parseValue();
            // A repeated key keeps its last value, like Python's json.loads.
            $canonical[$key] = $memberCanonical;
            $value[$key] = $memberValue;
            $this->skipWhitespace();
            if (',' === $this->peek()) {
                ++$this->pos;
                $this->skipWhitespace();
                continue;
            }
            $this->expect('}');
            break;
        }
        --$this->depth;

        return [self::joinMembers($canonical), $value];
    }

    /**
     * @return array{0: string, 1: list<mixed>}
     */
    private function parseArray(): array
    {
        $this->enter();
        ++$this->pos;
        $this->skipWhitespace();
        $canonical = [];
        $value = [];
        if (']' === $this->peek()) {
            ++$this->pos;
            --$this->depth;

            return ['[]', []];
        }
        while (true) {
            [$itemCanonical, $itemValue] = $this->parseValue();
            $canonical[] = $itemCanonical;
            $value[] = $itemValue;
            $this->skipWhitespace();
            if (',' === $this->peek()) {
                ++$this->pos;
                $this->skipWhitespace();
                continue;
            }
            $this->expect(']');
            break;
        }
        --$this->depth;

        return ['[' . implode(',', $canonical) . ']', $value];
    }

    private function parseString(): string
    {
        ++$this->pos;
        $out = '';
        while (true) {
            $run = strcspn($this->json, self::STRING_STOP, $this->pos);
            $out .= substr($this->json, $this->pos, $run);
            $this->pos += $run;
            if ($this->pos >= $this->length) {
                throw $this->syntaxError('unterminated string');
            }
            $char = $this->json[$this->pos];
            if ('"' === $char) {
                ++$this->pos;

                return $out;
            }
            if ('\\' !== $char) {
                throw $this->syntaxError('unescaped control character in a string');
            }
            $escape = $this->json[$this->pos + 1] ?? '';
            $this->pos += 2;
            $out .= match ($escape) {
                '"' => '"',
                '\\' => '\\',
                '/' => '/',
                'b' => "\x08",
                'f' => "\x0c",
                'n' => "\n",
                'r' => "\r",
                't' => "\t",
                'u' => $this->parseUnicodeEscape(),
                default => throw $this->syntaxError('invalid escape sequence'),
            };
        }
    }

    /**
     * Reads the four hex digits after `\u` (and a second escape for a
     * surrogate pair) and returns the character in UTF-8.
     */
    private function parseUnicodeEscape(): string
    {
        $codePoint = $this->readHex4();
        if ($codePoint >= 0xDC00 && $codePoint <= 0xDFFF) {
            throw $this->syntaxError('unpaired UTF-16 surrogate');
        }
        if ($codePoint >= 0xD800 && $codePoint <= 0xDBFF) {
            if ('\\u' !== substr($this->json, $this->pos, 2)) {
                throw $this->syntaxError('unpaired UTF-16 surrogate');
            }
            $this->pos += 2;
            $low = $this->readHex4();
            if ($low < 0xDC00 || $low > 0xDFFF) {
                throw $this->syntaxError('unpaired UTF-16 surrogate');
            }
            $codePoint = 0x10000 + (($codePoint - 0xD800) << 10) + ($low - 0xDC00);
        }

        return self::utf8($codePoint);
    }

    private function readHex4(): int
    {
        $hex = substr($this->json, $this->pos, 4);
        if (4 !== \strlen($hex) || 4 !== strspn($hex, '0123456789abcdefABCDEF')) {
            throw $this->syntaxError('invalid \u escape');
        }
        $this->pos += 4;

        return (int) hexdec($hex);
    }

    /**
     * @return array{0: string, 1: int|float|string}
     */
    private function parseNumber(): array
    {
        if (1 !== preg_match('/-?(?:0|[1-9][0-9]*)(\.[0-9]+)?([eE][+-]?[0-9]+)?/A', $this->json, $match, 0, $this->pos)) {
            throw $this->syntaxError('unexpected character');
        }
        $text = $match[0];
        $this->pos += \strlen($text);
        if ('' === ($match[1] ?? '') && '' === ($match[2] ?? '')) {
            // An integer keeps its exact digits, however large (Python int).
            if ('-0' === $text) {
                $text = '0';
            }
            $int = (int) $text;

            return [$text, (string) $int === $text ? $int : $text];
        }
        $float = (float) $text;

        return [self::formatFloat($float), $float];
    }

    private function expectLiteral(string $literal): void
    {
        if (0 !== substr_compare($this->json, $literal, $this->pos, \strlen($literal))) {
            throw $this->syntaxError('unexpected character');
        }
        $this->pos += \strlen($literal);
    }

    private function expect(string $char): void
    {
        if ($char !== $this->peek()) {
            throw $this->syntaxError(\sprintf('expected "%s"', $char));
        }
        ++$this->pos;
    }

    private function peek(): string
    {
        return $this->json[$this->pos] ?? '';
    }

    private function skipWhitespace(): void
    {
        $this->pos += strspn($this->json, " \t\n\r", $this->pos);
    }

    private function enter(): void
    {
        if (++$this->depth > self::MAX_DEPTH) {
            throw $this->syntaxError('nested deeper than ' . self::MAX_DEPTH . ' levels');
        }
    }

    private function syntaxError(string $problem): WebhookVerificationException
    {
        return self::invalid(\sprintf('The webhook body is not valid JSON: %s at byte %d.', $problem, $this->pos));
    }

    private static function encodeValue(mixed $value, int $depth): string
    {
        if ($depth > self::MAX_DEPTH) {
            throw self::invalid('The payload is nested deeper than ' . self::MAX_DEPTH . ' levels.');
        }
        if (null === $value) {
            return 'null';
        }
        if (\is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (\is_int($value)) {
            return (string) $value;
        }
        if (\is_float($value)) {
            return self::formatFloat($value);
        }
        if (\is_string($value)) {
            if (1 !== preg_match('//u', $value)) {
                throw self::invalid('The payload contains a string that is not valid UTF-8.');
            }

            return self::encodeString($value);
        }
        if (\is_array($value) && array_is_list($value)) {
            $items = [];
            foreach ($value as $item) {
                $items[] = self::encodeValue($item, $depth + 1);
            }

            return '[' . implode(',', $items) . ']';
        }
        if (\is_array($value) || $value instanceof \stdClass) {
            $members = [];
            foreach (\is_array($value) ? $value : get_object_vars($value) as $key => $member) {
                $key = (string) $key;
                if (1 !== preg_match('//u', $key)) {
                    throw self::invalid('The payload contains a key that is not valid UTF-8.');
                }
                $members[$key] = self::encodeValue($member, $depth + 1);
            }

            return self::joinMembers($members);
        }

        throw self::invalid(\sprintf('The payload contains a %s, which JSON cannot hold.', get_debug_type($value)));
    }

    /**
     * Sorts members by key and writes them as an object. Byte order of UTF-8
     * is code point order, which is how Python sorts `str` keys.
     *
     * @param array<array-key, string> $members key => canonical value
     */
    private static function joinMembers(array $members): string
    {
        ksort($members, \SORT_STRING);
        $parts = [];
        foreach ($members as $key => $member) {
            $parts[] = self::encodeString((string) $key) . ':' . $member;
        }

        return '{' . implode(',', $parts) . '}';
    }

    private static function encodeString(string $value): string
    {
        if (null === self::$escapes) {
            $escapes = [];
            for ($byte = 0; $byte < 0x20; ++$byte) {
                $escapes[\chr($byte)] = \sprintf('\u%04x', $byte);
            }
            $escapes["\x08"] = '\b';
            $escapes["\x0c"] = '\f';
            $escapes["\n"] = '\n';
            $escapes["\r"] = '\r';
            $escapes["\t"] = '\t';
            $escapes['"'] = '\"';
            $escapes['\\'] = '\\\\';
            self::$escapes = $escapes;
        }

        return '"' . strtr($value, self::$escapes) . '"';
    }

    private static function utf8(int $codePoint): string
    {
        if ($codePoint < 0x80) {
            return \chr($codePoint);
        }
        if ($codePoint < 0x800) {
            return \chr(0xC0 | ($codePoint >> 6)) . \chr(0x80 | ($codePoint & 0x3F));
        }
        if ($codePoint < 0x10000) {
            return \chr(0xE0 | ($codePoint >> 12)) . \chr(0x80 | (($codePoint >> 6) & 0x3F))
                . \chr(0x80 | ($codePoint & 0x3F));
        }

        return \chr(0xF0 | ($codePoint >> 18)) . \chr(0x80 | (($codePoint >> 12) & 0x3F))
            . \chr(0x80 | (($codePoint >> 6) & 0x3F)) . \chr(0x80 | ($codePoint & 0x3F));
    }

    /**
     * The exact decimal integer of an integral, finite float (Python's
     * `int(value)`), computed without bcmath or gmp.
     */
    private static function integralFloatToString(float $value): string
    {
        if ($value > -self::TWO_POW_63 && $value < self::TWO_POW_63) {
            return (string) (int) $value;
        }
        // |value| >= 2^63, so the float is normal: value = mantissa * 2^exponent.
        $bits = self::bits($value);
        $exponent = (($bits >> 52) & 0x7FF) - 1075;
        $mantissa = ($bits & 0xFFFFFFFFFFFFF) | (1 << 52);
        // Little-endian limbs in base 10^9; a limb shifted by 30 bits still fits an int.
        $limbs = [$mantissa % 1000000000, intdiv($mantissa, 1000000000)];
        while ($exponent > 0) {
            $shift = min($exponent, 30);
            $carry = 0;
            foreach ($limbs as $index => $limb) {
                $product = ($limb << $shift) + $carry;
                $limbs[$index] = $product % 1000000000;
                $carry = intdiv($product, 1000000000);
            }
            while ($carry > 0) {
                $limbs[] = $carry % 1000000000;
                $carry = intdiv($carry, 1000000000);
            }
            $exponent -= $shift;
        }
        $text = (string) array_pop($limbs);
        foreach (array_reverse($limbs) as $limb) {
            $text .= str_pad((string) $limb, 9, '0', \STR_PAD_LEFT);
        }

        return ($bits < 0 ? '-' : '') . $text;
    }

    /**
     * The shortest digits that read back as the same positive, finite float
     * (David Gay's dtoa mode 0, which Python's `repr` uses too) and the
     * position of the decimal point: value = 0.DIGITS * 10^point.
     *
     * @return array{0: string, 1: int}
     */
    private static function shortestDigits(float $value): array
    {
        if (0.0 === $value) {
            return ['0', 1];
        }
        $text = self::roundTripText($value);
        if (1 !== preg_match('/^(\d+)(?:\.(\d+))?(?:[eE]([+-]?\d+))?$/D', $text, $match)) {
            throw new \UnexpectedValueException(\sprintf('Unexpected float format "%s".', $text));
        }
        $integer = $match[1];
        $digits = $integer . ($match[2] ?? '');
        $point = \strlen($integer) + (int) ($match[3] ?? 0);
        $trimmed = ltrim($digits, '0');
        $point -= \strlen($digits) - \strlen($trimmed);

        return [rtrim($trimmed, '0'), $point];
    }

    /**
     * Shortest round-trip text of a positive float. PHP prints floats with
     * dtoa mode 0 when `serialize_precision` is -1, whatever the configured
     * value; the setting is changed only for this call.
     */
    private static function roundTripText(float $value): string
    {
        if (\function_exists('ini_set')) {
            $previous = ini_set('serialize_precision', '-1');
            if (false !== $previous) {
                try {
                    return var_export($value, true);
                } finally {
                    ini_set('serialize_precision', $previous);
                }
            }
        }

        return self::roundTripTextWithoutIni($value);
    }

    /**
     * Fallback for hosts that disable `ini_set`: the fewest digits that read
     * back as the same float, found by trying each precision in turn.
     */
    private static function roundTripTextWithoutIni(float $value): string
    {
        for ($precision = 0; $precision < 17; ++$precision) {
            $text = strtr(\sprintf('%.' . $precision . 'e', $value), ',', '.');
            if ((float) $text === $value) {
                return $text;
            }
            // Just above a power of two the gap below is half the gap above, so
            // the nearest decimal can miss while a neighbour still reads back.
            [$mantissa, $exponent] = explode('e', $text);
            $digits = (int) str_replace('.', '', $mantissa);
            $scale = (int) $exponent - $precision;
            foreach ([$digits + 1, $digits - 1] as $neighbour) {
                $candidate = $neighbour . 'e' . $scale;
                if ($neighbour > 0 && (float) $candidate === $value) {
                    return $candidate;
                }
            }
        }

        return strtr(\sprintf('%.16e', $value), ',', '.');
    }

    private static function signBit(float $value): bool
    {
        return self::bits($value) < 0;
    }

    /**
     * The IEEE 754 bits of a float as a signed 64-bit int.
     */
    private static function bits(float $value): int
    {
        /** @var array{1: int} $unpacked */
        $unpacked = unpack('J', pack('E', $value));

        return $unpacked[1];
    }

    private static function invalid(string $message): WebhookVerificationException
    {
        return new WebhookVerificationException(WebhookVerificationException::INVALID_PAYLOAD, $message);
    }
}
