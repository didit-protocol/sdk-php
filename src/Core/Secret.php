<?php

declare(strict_types=1);

namespace Didit\Core;

/**
 * A credential that `var_export()`, `var_dump()`, `print_r()` and
 * `serialize()` never reveal: the value lives outside the object, so no dump
 * of a client or its options contains it.
 *
 * @internal
 */
final class Secret
{
    /** @var \WeakMap<Secret, string>|null */
    private static ?\WeakMap $values = null;

    private function __construct()
    {
    }

    public static function of(#[\SensitiveParameter] string $value): self
    {
        $secret = new self();
        self::$values ??= new \WeakMap();
        self::$values[$secret] = $value;

        return $secret;
    }

    public function reveal(): string
    {
        $value = self::$values[$this] ?? null;

        return \is_string($value) ? $value : throw new \LogicException('The credential is not available.');
    }

    /**
     * @return array{value: string}
     */
    public function __debugInfo(): array
    {
        return ['value' => '[redacted]'];
    }

    /**
     * @return never
     */
    public function __serialize(): array
    {
        throw new \LogicException('Didit credentials cannot be serialized.');
    }

    private function __clone()
    {
    }
}
