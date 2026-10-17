<?php

declare(strict_types=1);

namespace Didit\Authentication;

use Didit\Exception\ConfigurationException;

/**
 * ES256 (ECDSA P-256 with SHA-256) with ext-openssl and nothing else.
 *
 * @internal
 */
final class Es256
{
    /**
     * DER prefix of a P-256 SubjectPublicKeyInfo: SEQUENCE { SEQUENCE {
     * id-ecPublicKey, prime256v1 }, BIT STRING } up to the uncompressed point.
     */
    private const SPKI_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    private function __construct()
    {
    }

    /**
     * @throws ConfigurationException when ext-openssl is missing
     */
    public static function requireOpenssl(): void
    {
        if (!\function_exists('openssl_verify') || !\function_exists('openssl_pkey_get_public')) {
            throw new ConfigurationException('Didit\Authentication needs the PHP openssl extension (ext-openssl) to verify ES256 tokens; install or enable it.');
        }
    }

    /**
     * The public key of an EC P-256 JWK, or null when the JWK is not a usable
     * P-256 signing key for ES256.
     *
     * @param array<array-key, mixed> $jwk
     */
    public static function publicKeyFromJwk(array $jwk): ?\OpenSSLAsymmetricKey
    {
        if (($jwk['kty'] ?? null) !== 'EC' || ($jwk['crv'] ?? null) !== 'P-256') {
            return null;
        }
        if (\array_key_exists('alg', $jwk) && 'ES256' !== $jwk['alg']) {
            return null;
        }
        if (\array_key_exists('use', $jwk) && 'sig' !== $jwk['use']) {
            return null;
        }
        $x = \is_string($jwk['x'] ?? null) ? Base64Url::decode($jwk['x']) : null;
        $y = \is_string($jwk['y'] ?? null) ? Base64Url::decode($jwk['y']) : null;
        if (null === $x || null === $y || 32 !== \strlen($x) || 32 !== \strlen($y)) {
            return null;
        }

        $der = hex2bin(self::SPKI_PREFIX) . "\x04" . $x . $y;
        $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
        $key = openssl_pkey_get_public($pem);
        self::clearErrors();
        if (false === $key) {
            // Not a point on the curve.
            return null;
        }
        $details = openssl_pkey_get_details($key);
        $ec = \is_array($details) ? $details['ec'] ?? null : null;
        if (!\is_array($ec) || \OPENSSL_KEYTYPE_EC !== $details['type'] || 'prime256v1' !== ($ec['curve_name'] ?? null)) {
            return null;
        }

        return $key;
    }

    /**
     * Verifies a JWS ES256 signature: 64 bytes, R then S, big-endian.
     */
    public static function verify(string $signingInput, string $signature, \OpenSSLAsymmetricKey $key): bool
    {
        if (64 !== \strlen($signature)) {
            return false;
        }
        $r = self::derInteger(substr($signature, 0, 32));
        $s = self::derInteger(substr($signature, 32, 32));
        if (null === $r || null === $s) {
            return false;
        }
        $sequence = $r . $s;
        $der = "\x30" . \chr(\strlen($sequence)) . $sequence;

        $result = openssl_verify($signingInput, $der, $key, \OPENSSL_ALGO_SHA256);
        self::clearErrors();

        return 1 === $result;
    }

    /**
     * A DER INTEGER for a 32-byte unsigned big-endian value, or null for zero.
     */
    private static function derInteger(string $bytes): ?string
    {
        $value = ltrim($bytes, "\x00");
        if ('' === $value) {
            return null;
        }
        if (\ord($value[0]) >= 0x80) {
            $value = "\x00" . $value;
        }

        return "\x02" . \chr(\strlen($value)) . $value;
    }

    private static function clearErrors(): void
    {
        while (false !== openssl_error_string()) {
            // Drain the queue so stale errors never surface elsewhere.
        }
    }
}
