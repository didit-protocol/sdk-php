<?php

declare(strict_types=1);

namespace Didit\Core;

use Didit\File;
use Psr\Http\Message\StreamInterface;

/**
 * JSON encoding of request bodies.
 *
 * @internal
 */
final class Json
{
    private function __construct()
    {
    }

    /**
     * @param mixed $hint where the value holds JSON objects (see {@see Operation::$jsonHints})
     */
    public static function encode(mixed $value, mixed $hint = null): string
    {
        return json_encode(
            self::prepare($value, $hint),
            \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION,
        );
    }

    /**
     * Converts date-times to ISO 8601 strings and empty arrays in object
     * positions to `{}`, recursively.
     */
    public static function prepare(mixed $value, mixed $hint = null): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }
        if ($value instanceof File || $value instanceof StreamInterface || \is_resource($value)) {
            throw new \InvalidArgumentException('Files can only be sent in multipart requests; this operation takes JSON.');
        }
        if (!\is_array($value)) {
            return $value;
        }
        $kind = \is_array($hint) ? ($hint[0] ?? null) : null;
        $children = \is_array($hint) ? ($hint[1] ?? null) : null;
        if ([] === $value && ('o' === $kind || 'm' === $kind)) {
            return new \stdClass();
        }
        $out = [];
        foreach ($value as $key => $item) {
            $childHint = match ($kind) {
                'o' => \is_array($children) ? ($children[$key] ?? null) : null,
                'l', 'm' => $children,
                default => null,
            };
            $out[$key] = self::prepare($item, $childHint);
        }

        return $out;
    }
}
