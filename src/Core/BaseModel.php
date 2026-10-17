<?php

declare(strict_types=1);

namespace Didit\Core;

/**
 * Base of every response type.
 *
 * Typed, read-only properties hold the fields the API documents. Every field
 * the API sent, documented or not, stays readable through {@see toArray()}
 * and array access (`$model['new_field']`), so new API fields never break
 * parsing and are never dropped. A field that is absent and a field that is
 * `null` both read as `null`; `toArray()` keeps the difference.
 *
 * @implements \ArrayAccess<string, mixed>
 *
 * @phpstan-consistent-constructor
 */
abstract class BaseModel implements \ArrayAccess, \JsonSerializable
{
    /** @var array<string, mixed> */
    private array $_data;

    private ?RawResponse $_rawResponse = null;

    /**
     * @param array<string, mixed> $data the decoded JSON object
     */
    public function __construct(array $data)
    {
        $this->_data = $data;
    }

    /**
     * @param array<string, mixed> $data the decoded JSON object
     */
    public static function fromArray(array $data): static
    {
        return new static($data);
    }

    /**
     * @internal builds the result of an API call
     */
    public static function fromResponse(RawResponse $response): static
    {
        $model = new static(Hydrate::assoc($response->parsedBody()) ?? []);
        $model->_rawResponse = $response;

        return $model;
    }

    /**
     * The HTTP response this object was read from, or null when it was built
     * from an array.
     */
    public function rawResponse(): ?RawResponse
    {
        return $this->_rawResponse;
    }

    /**
     * Every field the API sent, exactly as decoded from JSON.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->_data;
    }

    /**
     * Reads any field by its API name, including fields this SDK version does
     * not know yet.
     */
    public function get(string $field): mixed
    {
        return $this->_data[$field] ?? null;
    }

    /**
     * Whether the API sent the field at all (even as `null`).
     */
    public function has(string $field): bool
    {
        return \array_key_exists($field, $this->_data);
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->_data[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->_data[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): never
    {
        throw new \LogicException(static::class . ' is read-only.');
    }

    public function offsetUnset(mixed $offset): never
    {
        throw new \LogicException(static::class . ' is read-only.');
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->_data;
    }
}
