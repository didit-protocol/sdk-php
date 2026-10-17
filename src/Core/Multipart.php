<?php

declare(strict_types=1);

namespace Didit\Core;

use Didit\File;
use Psr\Http\Message\StreamInterface;

/**
 * `multipart/form-data` encoding (RFC 7578).
 *
 * Scalars are sent as text fields (booleans as `true`/`false`), files as file
 * parts, lists of scalars or files as repeated fields, and objects or lists
 * of objects as a JSON text field. Null values are left out.
 *
 * @internal
 */
final class Multipart
{
    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $fields
     */
    public static function containsFile(array $fields): bool
    {
        foreach ($fields as $value) {
            if (self::isFile($value)) {
                return true;
            }
            if (\is_array($value) && array_is_list($value)) {
                foreach ($value as $item) {
                    if (self::isFile($item)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $fields
     * @param array<string, mixed> $hints see {@see Operation::$jsonHints}
     */
    public static function encode(array $fields, string $boundary, array $hints = []): string
    {
        $body = '';
        foreach ($fields as $name => $value) {
            // Integer-like keys arrive as int.
            $name = "{$name}";
            if (null === $value) {
                continue;
            }
            if (\is_array($value) && array_is_list($value) && self::isFlatList($value)) {
                foreach ($value as $item) {
                    $body .= self::part($boundary, $name, $item);
                }
                continue;
            }
            $body .= self::part($boundary, $name, $value, $hints[$name] ?? null);
        }

        return $body . '--' . $boundary . "--\r\n";
    }

    private static function part(string $boundary, string $name, mixed $value, mixed $hint = null): string
    {
        $disposition = 'form-data; name="' . self::escape($name) . '"';
        if ($value instanceof File || $value instanceof StreamInterface || \is_resource($value)) {
            $file = $value instanceof File ? $value : File::fromStream($value);
            $disposition .= '; filename="' . self::escape($file->filename) . '"';
            if (1 === preg_match('/[^\x20-\x7e]/', $file->filename)) {
                $disposition .= "; filename*=UTF-8''" . rawurlencode($file->filename);
            }

            return '--' . $boundary . "\r\n"
                . 'Content-Disposition: ' . $disposition . "\r\n"
                . 'Content-Type: ' . $file->contentType . "\r\n\r\n"
                . $file->contents() . "\r\n";
        }

        $text = match (true) {
            \is_bool($value) => $value ? 'true' : 'false',
            \is_string($value) => $value,
            \is_int($value) => (string) $value,
            $value instanceof \DateTimeInterface => $value->format(\DateTimeInterface::ATOM),
            default => Json::encode($value, $hint),
        };

        return '--' . $boundary . "\r\n"
            . 'Content-Disposition: ' . $disposition . "\r\n\r\n"
            . $text . "\r\n";
    }

    /**
     * @param list<mixed> $value
     */
    private static function isFlatList(array $value): bool
    {
        foreach ($value as $item) {
            if (\is_array($item) || (\is_object($item) && !self::isFile($item) && !$item instanceof \DateTimeInterface)) {
                return false;
            }
        }

        return true;
    }

    private static function isFile(mixed $value): bool
    {
        return $value instanceof File || $value instanceof StreamInterface || \is_resource($value);
    }

    private static function escape(string $value): string
    {
        return str_replace(['"', "\r", "\n"], ['%22', '%0D', '%0A'], $value);
    }
}
