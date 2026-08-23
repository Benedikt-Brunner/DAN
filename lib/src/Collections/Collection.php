<?php

declare(strict_types=1);

namespace Dan\Lib\Collections;

use ArrayAccess;
use BadMethodCallException;
use Countable;
use IteratorAggregate;
use JsonSerializable;
use Traversable;

/**
 * @template Item
 *
 * @implements ArrayAccess<int, Item>
 * @implements IteratorAggregate<int, Item>
 */
readonly class Collection implements ArrayAccess, IteratorAggregate, Countable, JsonSerializable
{
    /**
     * @param list<Item> $items
     */
    final private function __construct(
        private array $items,
    ) {}

    /**
     * @param list<Item> $items
     */
    public static function create(array $items): static
    {
        return new static($items);
    }

    public function empty(): bool
    {
        return $this->count() === 0;
    }

    /**
     * @return list<Item>
     */
    public function getItems(): array
    {
        return $this->items;
    }

    /**
     * @return list<Item>
     */
    public function jsonSerialize(): array
    {
        return $this->getItems();
    }

    public function count(): int
    {
        return count($this->items);
    }

    /**
     * @return Traversable<int, Item>
     */
    public function getIterator(): Traversable
    {
        yield from $this->items;
    }

    public function offsetExists(mixed $offset): bool
    {
        return is_int($offset) && array_key_exists($offset, $this->items);
    }

    /**
     * @return Item
     */
    public function offsetGet(mixed $offset): mixed
    {
        return $this->items[$offset];
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new BadMethodCallException('Collection is immutable.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new BadMethodCallException('Collection is immutable.');
    }
}
