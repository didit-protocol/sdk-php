<?php

declare(strict_types=1);

namespace Didit\Authentication;

/**
 * Unpadded base64url (RFC 4648 section 5) with strict decoding.
 *
 * @internal
 */
final class Base64Url
{
    private function __construct()
    {
    }

    public static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /**
     * Decodes canonical unpadded base64url, or returns null: no padding, no
     * characters outside the alphabet, no impossible length and no stray
     * bits in the last character.
     */
    public static function decode(string $value): ?string
    {
        if (1 !== preg_match('/^[A-Za-z0-9_-]*$/D', $value) || 1 === \strlen($value) % 4) {
            return null;
        }
        $bytes = base64_decode(strtr($value, '-_', '+/'), true);
        if (false === $bytes || self::encode($bytes) !== $value) {
            return null;
        }

        return $bytes;
    }
}
