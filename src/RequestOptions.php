<?php

declare(strict_types=1);

namespace Didit;

/**
 * Per-request overrides of the client options. Every argument is optional;
 * null keeps the client's value.
 *
 * ```php
 * $didit->sessions->list([], new RequestOptions(timeout: 5, maxRetries: 0));
 * ```
 *
 * `idempotencyKey` exists only here, never on the client: the key (1 to 255
 * characters) is sent as the `Idempotency-Key` header on POST, PUT, PATCH and
 * DELETE, and a POST or PATCH that carries it is retried like a GET.
 *
 * ```php
 * $didit->sessions->create(['workflow_id' => $id], new RequestOptions(idempotencyKey: 'order-1042'));
 * ```
 */
final class RequestOptions
{
    /**
     * @param array<string, string> $headers extra headers for this request
     * @param array<string, scalar> $extraQuery extra query parameters for this request
     */
    public function __construct(
        #[\SensitiveParameter]
        public readonly ?string $apiKey = null,
        public readonly ?string $baseUrl = null,
        public readonly ?string $organizationId = null,
        public readonly ?string $applicationId = null,
        public readonly ?float $timeout = null,
        public readonly ?int $maxRetries = null,
        public readonly array $headers = [],
        public readonly array $extraQuery = [],
        public readonly ?string $accountBaseUrl = null,
        #[\SensitiveParameter]
        public readonly ?string $accountToken = null,
        public readonly ?string $idempotencyKey = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        /** @var array<string, mixed> $info */
        $info = get_object_vars($this);
        $info['apiKey'] = null === $this->apiKey ? null : '[redacted]';
        $info['accountToken'] = null === $this->accountToken ? null : '[redacted]';

        return $info;
    }
}
