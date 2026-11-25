<?php

declare(strict_types=1);

namespace Didit\Core;

/**
 * The result of an operation that answers without a body (204 No Content).
 */
final class NoContent
{
    public function __construct(
        private readonly ?RawResponse $rawResponse = null,
    ) {
    }

    /**
     * @internal
     */
    public static function fromResponse(RawResponse $response): self
    {
        return new self($response);
    }

    public function rawResponse(): ?RawResponse
    {
        return $this->rawResponse;
    }
}
