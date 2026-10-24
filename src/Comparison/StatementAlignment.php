<?php

declare(strict_types=1);

namespace Dan\Harness\Comparison;

/**
 * The baseline and candidate statement sequences of one cell lined up
 * against each other, in sequence order. Every position of either sequence
 * appears in exactly one item.
 */
final class StatementAlignment
{
    /**
     * @param list<AlignedStatement> $items
     */
    public function __construct(
        public readonly array $items,
    ) {}

    public function sqlChanged(): bool
    {
        return $this->changes() !== [];
    }

    /**
     * @return list<AlignedStatement> every item that is not an unchanged match
     */
    public function changes(): array
    {
        return array_values(array_filter(
            $this->items,
            fn (AlignedStatement $item): bool => $item->kind !== AlignmentKind::Unchanged,
        ));
    }

    /**
     * @return list<int> baseline statement positions aligned as the given kind
     */
    public function baselineIndices(AlignmentKind $kind): array
    {
        $indices = [];
        foreach ($this->items as $item) {
            if ($item->kind === $kind && $item->baselineIndex !== null) {
                $indices[] = $item->baselineIndex;
            }
        }

        return $indices;
    }

    /**
     * @return list<int> candidate statement positions aligned as the given kind
     */
    public function candidateIndices(AlignmentKind $kind): array
    {
        $indices = [];
        foreach ($this->items as $item) {
            if ($item->kind === $kind && $item->candidateIndex !== null) {
                $indices[] = $item->candidateIndex;
            }
        }

        return $indices;
    }
}
