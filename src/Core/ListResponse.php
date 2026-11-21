<?php

declare(strict_types=1);

namespace Didit\Core;

/**
 * A response whose body is a JSON array.
 *
 * @template TItem
 *
 * @implements \IteratorAggregate<int, TItem>
 * @implements \ArrayAccess<int, TItem>
 */
final class ListResponse implements \IteratorAggregate, \Countable, \ArrayAccess, \JsonSerializable
{
    /**
     * @param list<TItem> $items
     * @param list<mixed> $data the array exactly as decoded from JSON
     */
    public function __construct(
        public readonly array $items,
        private readonly array $data = [],
        private readonly ?RawResponse $rawResponse = null,
    ) {
    }

    /**
     * @internal
     *
     * @template T
     *
     * @param callable(mixed): (T|null) $item
     *
     * @return ListResponse<T>
     */
    public static function fromResponse(RawResponse $response, callable $item): self
    {
        $body = $response->parsedBody();
        $data = \is_array($body) && array_is_list($body) ? $body : [];

        return new self(Hydrate::list($data, $item) ?? [], $data, $response);
    }

    public function rawResponse(): ?RawResponse
    {
        return $this->rawResponse;
    }

    /**
     * @return list<mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }

    /**
     * @return \ArrayIterator<int, TItem>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->items);
    }

    public function count(): int
    {
        return \count($this->items);
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->items[$offset]);
    }

    /**
     * @return TItem|null
     */
    public function offsetGet(mixed $offset): mixed
    {
        return $this->items[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): never
    {
        throw new \LogicException('ListResponse is read-only.');
    }

    public function offsetUnset(mixed $offset): never
    {
        throw new \LogicException('ListResponse is read-only.');
    }

    /**
     * @return list<mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->data;
    }
}
