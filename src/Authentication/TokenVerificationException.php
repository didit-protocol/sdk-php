<?php

declare(strict_types=1);

namespace Didit\Authentication;

use Didit\Exception\DiditException;

/**
 * An access or ID token failed verification. {@see self::$reason} is
 * machine-readable; the message is for humans and never contains the token.
 */
class TokenVerificationException extends DiditException
{
    /** The token is not three strict base64url segments of JSON objects. */
    public const MALFORMED = 'malformed';

    /** The header `alg` is not `ES256`. */
    public const UNSUPPORTED_ALGORITHM = 'unsupported_algorithm';

    /** The header `typ` does not match the token type (`at+jwt` or `JWT`). */
    public const INVALID_TYPE = 'invalid_type';

    /** No EC P-256 key in the issuer's JWKS has the token's `kid`. */
    public const UNKNOWN_KEY = 'unknown_key';

    /** The signature does not verify with the issuer's key. */
    public const INVALID_SIGNATURE = 'invalid_signature';

    /** `iss` is not exactly the configured issuer. */
    public const INVALID_ISSUER = 'invalid_issuer';

    /** `aud` does not contain the expected audience. */
    public const INVALID_AUDIENCE = 'invalid_audience';

    /** A claim the verification needs (`exp`, `iat`) is absent or not a number. */
    public const MISSING_CLAIM = 'missing_claim';

    /** `exp` is in the past, beyond the leeway. */
    public const EXPIRED = 'expired';

    /** `nbf` is in the future, beyond the leeway. */
    public const NOT_YET_VALID = 'not_yet_valid';

    /** `iat` is in the future, beyond the leeway. */
    public const ISSUED_IN_FUTURE = 'issued_in_future';

    /** The ID token's `nonce` differs from the expected one. */
    public const INVALID_NONCE = 'invalid_nonce';

    public function __construct(
        public readonly string $reason,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
