<?php

declare(strict_types=1);

namespace Dan\Lib\Collections;

use Countable;
use IteratorAggregate;
use Traversable;

/**
 * An immutable set of distinct items in insertion order. Membership is by
 * strict identity (`===`), which is exactly right for enum cases, scalars,
 * and objects compared by instance.
 *
 * @template Item
 *
 * @implements IteratorAggregate<int, Item>
 */
readonly class Set implements IteratorAggregate, Countable
{
    /**
     * @param list<Item> $items distinct, in insertion order
     */
    final private function __construct(
        private array $items,
    ) {}

    /**
     * Duplicates collapse onto their first occurrence.
     *
     * @template T
     *
     * @param list<T> $items
     *
     * @return static<T>
     */
    public static function create(array $items): static
    {
        $distinct = [];
        foreach ($items as $item) {
            if (!in_array($item, $distinct, true)) {
                $distinct[] = $item;
            }
        }

        return new static($distinct);
    }

    /**
     * @param Item $item
     */
    public function contains(mixed $item): bool
    {
        return in_array($item, $this->items, true);
    }

    /**
     * Returns the set extended by the item; the receiver is left untouched.
     * Adding a member that is already present yields an equal set.
     *
     * @param Item $item
     */
    public function with(mixed $item): static
    {
        if ($this->contains($item)) {
            return $this;
        }

        return new static([
            ...$this->items,
            $item,
        ]);
    }

    public function empty(): bool
    {
        return $this->count() === 0;
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
}
