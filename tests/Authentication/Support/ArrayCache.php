<?php

declare(strict_types=1);

namespace Didit\Tests\Authentication\Support;

use Psr\SimpleCache\CacheInterface;

/**
 * An in-memory PSR-16 cache that honours TTLs against a test clock and
 * rejects keys PSR-16 does not guarantee.
 */
final class ArrayCache implements CacheInterface
{
    /** @var array<string, array{mixed, float|null}> */
    public array $items = [];

    /** @var list<string> Every key the SDK used, valid or not. */
    public array $keysUsed = [];

    public bool $broken = false;

    /**
     * @param \Closure(): (int|float) $clock
     */
    public function __construct(private readonly \Closure $clock)
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->check($key);
        if (!isset($this->items[$key])) {
            return $default;
        }
        [$value, $expiresAt] = $this->items[$key];
        if (null !== $expiresAt && $expiresAt <= ($this->clock)()) {
            unset($this->items[$key]);

            return $default;
        }

        return $value;
    }

    public function set(string $key, mixed $value, int|\DateInterval|null $ttl = null): bool
    {
        $this->check($key);
        if ($ttl instanceof \DateInterval) {
            throw new \LogicException('The SDK passes integer TTLs.');
        }
        $this->items[$key] = [unserialize(serialize($value)), null === $ttl ? null : ($this->clock)() + $ttl];

        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->items[$key]);

        return true;
    }

    public function clear(): bool
    {
        $this->items = [];

        return true;
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        throw new \LogicException('Not used by the SDK.');
    }

    /**
     * @param iterable<mixed, mixed> $values
     */
    public function setMultiple(iterable $values, int|\DateInterval|null $ttl = null): bool
    {
        throw new \LogicException('Not used by the SDK.');
    }

    public function deleteMultiple(iterable $keys): bool
    {
        throw new \LogicException('Not used by the SDK.');
    }

    public function has(string $key): bool
    {
        return null !== $this->get($key);
    }

    private function check(string $key): void
    {
        $this->keysUsed[] = $key;
        if ($this->broken) {
            throw new \RuntimeException('Cache backend is down.');
        }
        if (1 !== preg_match('/^[A-Za-z0-9_.]{1,64}$/D', $key)) {
            throw new \InvalidArgumentException('Key not guaranteed by PSR-16: ' . $key);
        }
    }
}
