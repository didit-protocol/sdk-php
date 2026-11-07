<?php

declare(strict_types=1);

namespace Didit\Core;

/**
 * How to send one API operation. Built by generated resource methods.
 *
 * @internal
 */
final class Operation
{
    public const AUTH_API_KEY = 'api_key';
    public const AUTH_ACCOUNT_TOKEN = 'account_token';
    public const AUTH_NONE = 'none';

    public const BASE_VERIFICATION = 'verification';
    public const BASE_ACCOUNT = 'account';

    /**
     * @param string $path path template, `{name}` placeholders
     * @param array<string, string> $query parameter key => query parameter name
     * @param array<string, string> $headers parameter key => header name
     * @param list<string> $bodyTypes accepted request encodings: `json`, `multipart`, `form`; empty when the operation has no body
     * @param string $accept the `Accept` header
     * @param array<string, mixed> $jsonHints body fields that are JSON objects, so an empty PHP array is sent as `{}`:
     *                                        `['o', [field => hint]]` object, `['m', hint]` map, `['l', hint]` list
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly string $auth = self::AUTH_API_KEY,
        public readonly bool $applicationScope = false,
        public readonly string $base = self::BASE_VERIFICATION,
        public readonly array $query = [],
        public readonly array $headers = [],
        public readonly array $bodyTypes = [],
        public readonly string $accept = 'application/json',
        public readonly array $jsonHints = [],
    ) {
    }
}
