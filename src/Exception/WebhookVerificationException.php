<?php

declare(strict_types=1);

namespace Didit\Exception;

/**
 * A webhook delivery failed verification. {@see self::$reason} is
 * machine-readable; the message is for humans.
 */
class WebhookVerificationException extends DiditException
{
    /** The signature headers are absent. */
    public const MISSING_SIGNATURE = 'missing_signature';

    /** No signature header matches any of the secrets. */
    public const INVALID_SIGNATURE = 'invalid_signature';

    /** `X-Timestamp` differs from the body's `timestamp`. */
    public const TIMESTAMP_MISMATCH = 'timestamp_mismatch';

    /** The timestamp is further from now than the tolerance allows. */
    public const TIMESTAMP_OUT_OF_TOLERANCE = 'timestamp_out_of_tolerance';

    /** The body has no integer `timestamp`. */
    public const MISSING_TIMESTAMP = 'missing_timestamp';

    /** The body is not a JSON object. */
    public const INVALID_PAYLOAD = 'invalid_payload';

    public function __construct(
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }
}
