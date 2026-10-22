<?php

declare(strict_types=1);

namespace Didit\Core;

/**
 * One page of a list (`limit`/`offset` pagination) plus auto-pagination.
 *
 * `$page->results` holds this page's items. Iterating the page walks every
 * item of every page, fetching the next page only when needed:
 *
 * ```php
 * foreach ($didit->sessions->list(['limit' => 50]) as $session) {
 *     // every session, page after page
 * }
 * ```
 *
 * Fields the API adds to a list response beyond `count`, `next`, `previous`
 * and `results` stay readable with array access (`$page['kpis']`).
 *
 * @template TItem
 *
 * @implements \IteratorAggregate<int, TItem>
 * @implements \ArrayAccess<string, mixed>
 */
final class Page implements \IteratorAggregate, \ArrayAccess
{
    /** Total number of items across all pages, when the API reports it. */
    public readonly ?int $count;

    /** URL of the next page, or null on the last page. */
    public readonly ?string $next;

    /** URL of the previous page, or null on the first page. */
    public readonly ?string $previous;

    /** @var list<TItem> the items of this page */
    public readonly array $results;

    /** @var array<string, mixed> */
    private readonly array $data;

    /**
     * @param list<TItem> $results
     * @param array<string, mixed> $data
     * @param (\Closure(string): Page<TItem>)|null $fetchNext
     */
    public function __construct(
        array $results,
        ?int $count = null,
        ?string $next = null,
        ?string $previous = null,
        array $data = [],
        private readonly ?\Closure $fetchNext = null,
        private readonly ?RawResponse $rawResponse = null,
    ) {
        $this->results = $results;
        $this->count = $count;
        $this->next = $next;
        $this->previous = $previous;
        $this->data = $data;
    }

    /**
     * @internal
     *
     * @template T
     *
     * @param callable(mixed): (T|null) $item
     * @param (\Closure(string): Page<T>)|null $fetchNext
     *
     * @return Page<T>
     */
    public static function fromResponse(RawResponse $response, callable $item, ?\Closure $fetchNext): self
    {
        $data = Hydrate::assoc($response->parsedBody()) ?? [];

        return new self(
            results: Hydrate::list($data['results'] ?? null, $item) ?? [],
            count: Hydrate::int($data['count'] ?? null),
            next: Hydrate::string($data['next'] ?? null),
            previous: Hydrate::string($data['previous'] ?? null),
            data: $data,
            fetchNext: $fetchNext,
            rawResponse: $response,
        );
    }

    public function hasNextPage(): bool
    {
        return null !== $this->next && '' !== $this->next && [] !== $this->results;
    }

    /**
     * Fetches the next page, or returns null on the last page.
     *
     * @return Page<TItem>|null
     */
    public function nextPage(): ?self
    {
        if (!$this->hasNextPage() || null === $this->fetchNext || null === $this->next) {
            return null;
        }

        return ($this->fetchNext)($this->next);
    }

    /**
     * Every item of this page and the following pages.
     *
     * @return \Generator<int, TItem>
     */
    public function autoPagingIterator(): \Generator
    {
        $page = $this;
        $index = 0;
        while (null !== $page) {
            foreach ($page->results as $item) {
                yield $index++ => $item;
            }
            $page = $page->nextPage();
        }
    }

    /**
     * Calls `$callback` for every item of this page and the following pages;
     * return `false` from the callback to stop.
     *
     * @param callable(TItem): (bool|void) $callback
     */
    public function each(callable $callback): void
    {
        foreach ($this->autoPagingIterator() as $item) {
            if (false === $callback($item)) {
                return;
            }
        }
    }

    /**
     * @return \Generator<int, TItem>
     */
    public function getIterator(): \Generator
    {
        return $this->autoPagingIterator();
    }

    public function rawResponse(): ?RawResponse
    {
        return $this->rawResponse;
    }

    /**
     * The page exactly as decoded from JSON.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->data[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->data[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): never
    {
        throw new \LogicException('Page is read-only.');
    }

    public function offsetUnset(mixed $offset): never
    {
        throw new \LogicException('Page is read-only.');
    }
}
