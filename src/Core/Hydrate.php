<?php

declare(strict_types=1);

namespace Didit\Core;

/**
 * Lenient conversions from decoded JSON to typed properties.
 *
 * A value of the wrong type becomes null instead of an error, so a response
 * that drifts from the spec never breaks parsing; the original value stays
 * readable through {@see BaseModel::toArray()}.
 *
 * @internal used by generated code
 */
final class Hydrate
{
    private function __construct()
    {
    }

    public static function string(mixed $value): ?string
    {
        return \is_string($value) ? $value : null;
    }

    public static function int(mixed $value): ?int
    {
        if (\is_int($value)) {
            return $value;
        }
        if (\is_float($value) && floor($value) === $value && abs($value) < 9.0E15) {
            return (int) $value;
        }

        return null;
    }

    public static function float(mixed $value): ?float
    {
        if (\is_float($value) || \is_int($value)) {
            return (float) $value;
        }

        return null;
    }

    public static function bool(mixed $value): ?bool
    {
        return \is_bool($value) ? $value : null;
    }

    /**
     * Parses an ISO 8601 date-time. Strings that are not ISO 8601 return null
     * (the raw string stays available).
     */
    public static function dateTime(mixed $value): ?\DateTimeImmutable
    {
        if (!\is_string($value) || 1 !== preg_match('/^\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}(:\d{2}(\.\d+)?)?)?(Z|[+-]\d{2}(:?\d{2})?)?$/i', $value)) {
            return null;
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Any JSON value, as decoded.
     */
    public static function mixed(mixed $value): mixed
    {
        return $value;
    }

    /**
     * A JSON object as an associative array.
     *
     * @return array<string, mixed>|null
     */
    public static function assoc(mixed $value): ?array
    {
        if (!\is_array($value) || ([] !== $value && array_is_list($value))) {
            return null;
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * @template T of BaseModel
     *
     * @param class-string<T> $class
     *
     * @return T|null
     */
    public static function model(mixed $value, string $class): ?BaseModel
    {
        $data = self::assoc($value);

        return null === $data ? null : $class::fromArray($data);
    }

    /**
     * A JSON array; items the converter rejects (returns null for) are left out.
     *
     * @template T
     *
     * @param callable(mixed): (T|null) $item
     *
     * @return list<T>|null
     */
    public static function list(mixed $value, callable $item): ?array
    {
        if (!\is_array($value) || !array_is_list($value)) {
            return null;
        }
        $out = [];
        foreach ($value as $element) {
            $converted = $item($element);
            if (null !== $converted) {
                $out[] = $converted;
            }
        }

        return $out;
    }

    /**
     * A JSON array whose items may be null.
     *
     * @template T
     *
     * @param callable(mixed): (T|null) $item
     *
     * @return list<T|null>|null
     */
    public static function nullableList(mixed $value, callable $item): ?array
    {
        if (!\is_array($value) || !array_is_list($value)) {
            return null;
        }
        $out = [];
        foreach ($value as $element) {
            $out[] = $item($element);
        }

        return $out;
    }

    /**
     * A JSON object used as a map; values the converter rejects are left out.
     *
     * @template T
     *
     * @param callable(mixed): (T|null) $item
     *
     * @return array<string, T>|null
     */
    public static function map(mixed $value, callable $item): ?array
    {
        $data = self::assoc($value);
        if (null === $data) {
            return null;
        }
        $out = [];
        foreach ($data as $key => $element) {
            $converted = $item($element);
            if (null !== $converted) {
                $out[(string) $key] = $converted;
            }
        }

        return $out;
    }
}
