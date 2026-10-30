<?php

declare(strict_types=1);

namespace Didit\Core;

/**
 * A response the API documents without a fixed schema (free-form JSON).
 *
 * @implements \ArrayAccess<string|int, mixed>
 */
final class JsonResponse implements \ArrayAccess, \JsonSerializable
{
    /**
     * @param mixed $data the body exactly as decoded from JSON
     */
    public function __construct(
        public readonly mixed $data,
        private readonly ?RawResponse $rawResponse = null,
    ) {
    }

    /**
     * @internal
     */
    public static function fromResponse(RawResponse $response): self
    {
        return new self($response->parsedBody(), $response);
    }

    public function rawResponse(): ?RawResponse
    {
        return $this->rawResponse;
    }

    /**
     * The body as an array, or an empty array when it is not a JSON object or array.
     *
     * @return array<mixed>
     */
    public function toArray(): array
    {
        return \is_array($this->data) ? $this->data : [];
    }

    public function offsetExists(mixed $offset): bool
    {
        return \is_array($this->data) && isset($this->data[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return \is_array($this->data) ? ($this->data[$offset] ?? null) : null;
    }

    public function offsetSet(mixed $offset, mixed $value): never
    {
        throw new \LogicException('JsonResponse is read-only.');
    }

    public function offsetUnset(mixed $offset): never
    {
        throw new \LogicException('JsonResponse is read-only.');
    }

    public function jsonSerialize(): mixed
    {
        return $this->data;
    }
}
