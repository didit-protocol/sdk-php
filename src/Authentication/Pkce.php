<?php

declare(strict_types=1);

namespace Didit\Authentication;

/**
 * A PKCE pair (RFC 7636) with the S256 method.
 *
 * Send {@see self::$challenge} and {@see self::$method} when sign-in
 * starts, keep {@see self::$verifier} server-side (for example in the
 * session), and pass it to {@see Authentication::exchangeCode()}.
 */
final class Pkce
{
    public const METHOD_S256 = 'S256';

    /** The `code_challenge_method`: always `S256`. */
    public readonly string $method;

    private function __construct(
        /** The `code_verifier`: keep it secret until the code exchange. */
        public readonly string $verifier,
        /** The `code_challenge`: base64url(SHA-256(verifier)). */
        public readonly string $challenge,
    ) {
        $this->method = self::METHOD_S256;
    }

    /**
     * Builds the pair for an existing verifier.
     *
     * @throws \InvalidArgumentException when the verifier is not 43 to 128
     *                                   characters of `A-Z a-z 0-9 - . _ ~`
     */
    public static function fromVerifier(string $verifier): self
    {
        if (1 !== preg_match('/^[A-Za-z0-9._~-]{43,128}$/D', $verifier)) {
            throw new \InvalidArgumentException('A PKCE code verifier is 43 to 128 characters of A-Z, a-z, 0-9, "-", ".", "_" and "~".');
        }

        return new self($verifier, Base64Url::encode(hash('sha256', $verifier, true)));
    }
}
