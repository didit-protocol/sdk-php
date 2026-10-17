<?php

declare(strict_types=1);

namespace Didit\Tests\Webhooks;

use Didit\Exception\WebhookVerificationException;
use Didit\Webhooks\CanonicalJson;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every expected string here was produced by Python 3.11 running
 * `json.dumps(shorten_floats(json.loads(text)), sort_keys=True,
 * separators=(",", ":"), ensure_ascii=False)`, with `shorten_floats` copied
 * from the backend.
 */
final class CanonicalJsonTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function numbers(): array
    {
        return [
            'integer above 2^63' => ['12345678901234567890123456789', '12345678901234567890123456789'],
            'negative integer below -2^63' => ['-9223372036854775809', '-9223372036854775809'],
            'integer 2^64' => ['18446744073709551616', '18446744073709551616'],
            'integer above 2^53' => ['9007199254740993', '9007199254740993'],
            'negative zero integer' => ['-0', '0'],
            'float 1e-05' => ['1e-05', '1e-05'],
            'float 1.5e-05' => ['1.5e-05', '1.5e-05'],
            'float 1.25e-5' => ['1.25e-5', '1.25e-05'],
            'float 2e-7' => ['2e-7', '2e-07'],
            'float 0.0001' => ['0.0001', '0.0001'],
            'float 0.1' => ['0.1', '0.1'],
            'float 0.30000000000000004' => ['0.30000000000000004', '0.30000000000000004'],
            'float 1e16' => ['1e16', '10000000000000000'],
            'float 1e22' => ['1e22', '10000000000000000000000'],
            'float 1E5' => ['1E5', '100000'],
            'float 1.0' => ['1.0', '1'],
            'float 87.0' => ['87.0', '87'],
            'float 2.5E-3' => ['2.5E-3', '0.0025'],
            'float 123456789.123' => ['123456789.123', '123456789.123'],
            'float 1234567890123456.7' => ['1234567890123456.7', '1234567890123456.8'],
            'float 9.999999999999999e15' => ['9.999999999999999e15', '10000000000000000'],
            'negative zero float' => ['-0.0', '0'],
            'float -2.5e-10' => ['-2.5e-10', '-2.5e-10'],
            'smallest subnormal' => ['5e-324', '5e-324'],
            'smallest subnormal long form' => ['4.9406564584124654e-324', '5e-324'],
            'smallest normal' => ['2.2250738585072014e-308', '2.2250738585072014e-308'],
            'largest float' => ['1.7976931348623157e308', '179769313486231570814527423731704356798070567525844996598917476803157260780028538760589558632766878171540458953514382464234321326889464182768467546703537516986049910576551282076245490090389328944075868508455133942304583236903222948165808559332123348274797826204144723168738177180919299881250404026184124858368'],
            'float 1e300' => ['1e300', '1000000000000000052504760255204420248704468581108159154915854115511802457988908195786371375080447864043704443832883878176942523235360430575644792184786706982848387200926575803737830233794788090059368953234970799945081119038967640880074652742780142494579258788820056842838115669472196386865459400540160'],
            'overflow' => ['1e400', 'Infinity'],
            'negative overflow' => ['-1e400', '-Infinity'],
            'underflow' => ['1e-400', '0'],
            'negative underflow' => ['-1e-400', '0'],
            'mixed list' => ['[1e15, 1e16, 1.5e15, -1, 0, 0.5]', '[1000000000000000,10000000000000000,1500000000000000,-1,0,0.5]'],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function strings(): array
    {
        return [
            'short escapes' => ['"\"\\\\\/\b\f\n\r\t"', '"\"\\\\/\b\f\n\r\t"'],
            'other control characters' => ['"\\u0000\\u0001\\u0007\\u000b\\u001f"', '"\\u0000\\u0001\\u0007\\u000b\\u001f"'],
            'raw DEL, U+2028 and U+2029 stay literal' => ["\"a/b\x7f\xe2\x80\xa8\xe2\x80\xa9\"", "\"a/b\x7f\xe2\x80\xa8\xe2\x80\xa9\""],
            'escaped slash, DEL, U+2028 and U+2029 become literal' => ['"a\/b\\u007f\\u2028\\u2029"', "\"a/b\x7f\xe2\x80\xa8\xe2\x80\xa9\""],
            'escaped non-ASCII becomes literal UTF-8' => ['"\\u00e9\\u5c71\\ud83d\\ude00"', "\"\xc3\xa9\xe5\xb1\xb1\xf0\x9f\x98\x80\""],
            'uppercase hex escapes' => ['"\\u00C9\\uD83D\\uDE00"', "\"\xc3\x89\xf0\x9f\x98\x80\""],
            'raw UTF-8' => ["\"Jos\xc3\xa9 \xe5\xb1\xb1\xe7\x94\xb0 \xf0\x9f\x98\x80\"", "\"Jos\xc3\xa9 \xe5\xb1\xb1\xe7\x94\xb0 \xf0\x9f\x98\x80\""],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function structures(): array
    {
        return [
            'keys by code point, astral after U+FFFF' => ['{"\\uffff":1,"\\ud800\\udc00":2,"\\ud83d\\ude00":3,"\\ufb00":4,"z":5,"Z":6,"\\u00e9":7,"10":8,"9":9,"":10,"_":11,"\\u0000":12}', "{\"\":10,\"\\u0000\":12,\"10\":8,\"9\":9,\"Z\":6,\"_\":11,\"z\":5,\"\xc3\xa9\":7,\"\xef\xac\x80\":4,\"\xef\xbf\xbf\":1,\"\xf0\x90\x80\x80\":2,\"\xf0\x9f\x98\x80\":3}"],
            'escaped key equals raw key' => ["{\"\\u00e9\":1,\"\xc3\xa9\":2}", "{\"\xc3\xa9\":2}"],
            'duplicate keys keep the last value' => ['{"a":1,"b":2,"a":3}', '{"a":3,"b":2}'],
            'nested duplicate keys' => ['{"x":{"k":1,"k":{"z":1,"a":2}}}', '{"x":{"k":{"a":2,"z":1}}}'],
            'whitespace' => [" {\n\t\"b\" : [ 1 , 2 ] ,\r\n \"a\" : { } } ", '{"a":{},"b":[1,2]}'],
            'empty containers' => ['{"a":{},"b":[],"c":[{}],"d":[[]]}', '{"a":{},"b":[],"c":[{}],"d":[[]]}'],
            'literals' => ['[true,false,null]', '[true,false,null]'],
            'top-level string' => ['"x"', '"x"'],
            'top-level number' => ['42', '42'],
        ];
    }

    #[DataProvider('numbers')]
    #[DataProvider('strings')]
    #[DataProvider('structures')]
    public function testCanonicalizeMatchesPython(string $json, string $expected): void
    {
        self::assertSame($expected, CanonicalJson::canonicalize($json));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidJson(): array
    {
        return [
            'empty' => [''],
            'whitespace only' => [" \n"],
            'unterminated object' => ['{"a":1'],
            'trailing comma in object' => ['{"a":1,}'],
            'trailing comma in array' => ['[1,]'],
            'missing colon' => ['{"a" 1}'],
            'number key' => ['{1:2}'],
            'single quotes' => ["{'a':1}"],
            'leading zero' => ['01'],
            'fraction without digits' => ['1.'],
            'fraction without integer' => ['.5'],
            'lone minus' => ['-'],
            'plus sign' => ['+1'],
            'NaN' => ['NaN'],
            'Infinity' => ['Infinity'],
            'truncated literal' => ['tru'],
            'unterminated string' => ['"abc'],
            'raw control character in string' => ["\"a\x01b\""],
            'raw newline in string' => ["\"a\nb\""],
            'invalid escape' => ['"\x"'],
            'short unicode escape' => ['"\\u12"'],
            'lone high surrogate' => ['"\\ud800"'],
            'lone low surrogate' => ['"\\udc00"'],
            'high surrogate before a non-surrogate' => ['"\\ud800\\u0041"'],
            'invalid UTF-8' => ["\"\xff\""],
            'UTF-8 byte order mark' => ["\xef\xbb\xbf{}"],
            'data after the value' => ['{"a":1} x'],
            'two values' => ['1 2'],
            'too deep' => [str_repeat('[', 513) . str_repeat(']', 513)],
        ];
    }

    #[DataProvider('invalidJson')]
    public function testInvalidJsonIsAnInvalidPayload(string $json): void
    {
        try {
            CanonicalJson::canonicalize($json);
        } catch (WebhookVerificationException $exception) {
            self::assertSame(WebhookVerificationException::INVALID_PAYLOAD, $exception->reason);

            return;
        }
        self::fail('Expected invalid_payload for ' . $json);
    }

    public function testNestingUpTo512LevelsIsAccepted(): void
    {
        $json = str_repeat('[', 512) . str_repeat(']', 512);
        self::assertSame($json, CanonicalJson::canonicalize($json));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function documents(): array
    {
        return [
            'object' => ['{"b":[1,2.5,-0,-0.0,true,null,{}],"a":{"10":1,"9":2,"":3},"big":12345678901234567890,"neg":-12345678901234567890}'],
            'duplicate keys' => ['{"a":1,"b":2,"a":{"c":3}}'],
            'nested empty' => ['[[],{},[{}]]'],
            'floats' => ['[1e-05,1e16,1e400,5e-324,0.1]'],
            'strings' => ['["\\u00e9\\ud83d\\ude00","\\u0000\\n",""]'],
        ];
    }

    /**
     * The value parse() returns for the event is what json_decode() with
     * JSON_BIGINT_AS_STRING returns, the shape the rest of the SDK uses.
     */
    #[DataProvider('documents')]
    public function testParseDecodesLikeJsonDecode(string $json): void
    {
        $expected = json_decode($json, true, 512, \JSON_THROW_ON_ERROR | \JSON_BIGINT_AS_STRING);
        self::assertSame($expected, CanonicalJson::parse($json)[1]);
        self::assertSame(CanonicalJson::canonicalize($json), CanonicalJson::parse($json)[0]);
    }

    public function testEncodeWritesDecodedValues(): void
    {
        $object = new \stdClass();
        $object->z = 1.0;
        $object->{'10'} = [];
        $object->{''} = new \stdClass();
        self::assertSame(
            '{"a":[1,2.5,true,false,null,"x"],"b":{"":{},"10":[],"z":1},"c":[],"d":{"10":"x","9":"y"}}',
            CanonicalJson::encode([
                'd' => [10 => 'x', 9 => 'y'],
                'c' => [],
                'b' => $object,
                'a' => [1, 2.5, true, false, null, 'x'],
            ]),
        );
        self::assertSame('{}', CanonicalJson::encode(new \stdClass()));
        self::assertSame('[]', CanonicalJson::encode([]));
        self::assertSame('9223372036854775807', CanonicalJson::encode(\PHP_INT_MAX));
        self::assertSame('-9223372036854775808', CanonicalJson::encode(\PHP_INT_MIN));
        self::assertSame('87', CanonicalJson::encode(87.0));
        self::assertSame('10000000000000000', CanonicalJson::encode(1e16));
        self::assertSame('1e-05', CanonicalJson::encode(0.00001));
        self::assertSame('0', CanonicalJson::encode(-0.0));
        self::assertSame('NaN', CanonicalJson::encode(\NAN));
        self::assertSame('-Infinity', CanonicalJson::encode(-\INF));
        self::assertSame("\"\xe2\x80\xa8/\\u0001\"", CanonicalJson::encode("\xe2\x80\xa8/\x01"));
    }

    public function testEncodeMatchesCanonicalizeOnDecodedObjects(): void
    {
        $json = '{"b":{"y":[],"x":{}},"a":[1.5,{"k":"\\u00e9"}],"10":null}';
        self::assertSame(CanonicalJson::canonicalize($json), CanonicalJson::encode(json_decode($json)));
        // An array cannot tell {} from []: empty objects come back as arrays.
        self::assertSame('{"10":null,"a":[1.5,{"k":"' . "\xc3\xa9" . '"}],"b":{"x":[],"y":[]}}', CanonicalJson::encode(json_decode($json, true)));
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function unencodable(): array
    {
        return [
            'invalid UTF-8 string' => [["\xff"]],
            'invalid UTF-8 key' => [["\xff" => 1]],
            'object other than stdClass' => [['when' => new \DateTimeImmutable()]],
            'closure' => [[static fn (): int => 1]],
        ];
    }

    #[DataProvider('unencodable')]
    public function testEncodeRejectsValuesJsonCannotHold(mixed $value): void
    {
        $this->expectException(WebhookVerificationException::class);
        CanonicalJson::encode($value);
    }

    public function testFloatsDoNotDependOnPrecisionSettings(): void
    {
        $precision = ini_get('precision');
        $serializePrecision = ini_get('serialize_precision');
        self::assertIsString($precision);
        self::assertIsString($serializePrecision);
        ini_set('precision', '3');
        ini_set('serialize_precision', '5');
        try {
            self::assertSame('[0.30000000000000004,1.5e-05,123456789.123]', CanonicalJson::canonicalize('[0.30000000000000004,1.5e-05,123456789.123]'));
            self::assertSame('0.30000000000000004', CanonicalJson::pythonRepr(0.1 + 0.2));
            self::assertSame('5', ini_get('serialize_precision'));
        } finally {
            ini_set('precision', $precision);
            ini_set('serialize_precision', $serializePrecision);
        }
    }

    /**
     * @return array<string, array{float, string}>
     */
    public static function pythonReprs(): array
    {
        return [
            'integral' => [87.0, '87.0'],
            'zero' => [0.0, '0.0'],
            'negative zero' => [-0.0, '-0.0'],
            '1e16' => [1e16, '1e+16'],
            '1.2345e16' => [1.2345e16, '1.2345e+16'],
            '1e22' => [1e22, '1e+22'],
            '1.5e300' => [1.5e300, '1.5e+300'],
            '9999999999999998.0' => [9999999999999998.0, '9999999999999998.0'],
            '0.1' => [0.1, '0.1'],
            '1e-05' => [0.00001, '1e-05'],
            '0.0001' => [0.0001, '0.0001'],
            '5e-324' => [5e-324, '5e-324'],
            'negative' => [-2.5, '-2.5'],
            'nan' => [\NAN, 'nan'],
            'inf' => [\INF, 'inf'],
            '-inf' => [-\INF, '-inf'],
        ];
    }

    /**
     * Python's `repr()` of a float, which the `X-Signature-Simple` string uses.
     */
    #[DataProvider('pythonReprs')]
    public function testPythonRepr(float $value, string $expected): void
    {
        self::assertSame($expected, CanonicalJson::pythonRepr($value));
    }

    /**
     * Hosts that disable ini_set() get the shortest digits by trial; the
     * powers of two (where the gap below is half the gap above) are where a
     * naive search goes wrong.
     *
     * @return array<string, array{string, string}>
     */
    public static function fallbackCases(): array
    {
        return [
            '0.1' => ['3fb999999999999a', '0.1'],
            '1e-05' => ['3ee4f8b588e368f1', '1e-05'],
            '2^-1015' => ['0060000000000000', '7.120236347223045e-306'],
            '2^-1006' => ['0100000000000000', '7.291122019556398e-303'],
            'smallest normal' => ['0010000000000000', '2.2250738585072014e-308'],
            'smallest subnormal' => ['0000000000000001', '5e-324'],
            'largest float' => ['7fefffffffffffff', '1.7976931348623157e+308'],
            '123456789.123' => ['419d6f34547df3b6', '123456789.123'],
        ];
    }

    #[DataProvider('fallbackCases')]
    public function testShortestDigitsWithoutIniSet(string $bits, string $pythonRepr): void
    {
        $unpacked = unpack('E', (string) hex2bin($bits));
        self::assertIsArray($unpacked);
        $value = $unpacked[1];
        self::assertIsFloat($value);
        self::assertSame($pythonRepr, CanonicalJson::pythonRepr($value));

        $method = new \ReflectionMethod(CanonicalJson::class, 'roundTripTextWithoutIni');
        $text = $method->invoke(null, $value);
        self::assertIsString($text);
        self::assertSame($value, (float) $text);
        self::assertSame(self::digits($pythonRepr), self::digits($text));
    }

    /**
     * Significant digits and decimal exponent of a number written in any
     * notation, for example `1.5e-05` gives `15e-6`.
     */
    private static function digits(string $number): string
    {
        self::assertSame(1, preg_match('/^(\d+)(?:\.(\d+))?(?:e([+-]?\d+))?$/', $number, $match));
        $digits = $match[1] . ($match[2] ?? '');
        $exponent = (int) ($match[3] ?? 0) - \strlen($match[2] ?? '');
        $trimmed = rtrim(ltrim($digits, '0'), '0');
        $exponent += \strlen(ltrim($digits, '0')) - \strlen($trimmed);

        return $trimmed . 'e' . $exponent;
    }
}
