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

    public static function encode(mixed $value): string
    {
        return json_encode(
            self::prepare($value),
            \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION,
        );
    }

    /**
     * Converts date-times to ISO 8601 strings, recursively.
     */
    private static function prepare(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }
        if ($value instanceof File || $value instanceof StreamInterface || \is_resource($value)) {
            throw new \InvalidArgumentException('Files can only be sent in multipart requests; this operation takes JSON.');
        }
        if (\is_array($value)) {
            $out = [];
            foreach ($value as $key => $item) {
                $out[$key] = self::prepare($item);
            }

            return $out;
        }

        return $value;
    }
}
