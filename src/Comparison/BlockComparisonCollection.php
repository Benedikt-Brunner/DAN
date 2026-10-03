<?php

declare(strict_types=1);

namespace Dan\Harness\Comparison;

use Dan\Lib\Collections\Collection;
use Dan\Lib\Collections\Set;
use Dan\Lib\Order\Ordering;

/**
 * The mirrored block pairs of one compared cell, in block order.
 *
 * @extends Collection<BlockComparison>
 */
final readonly class BlockComparisonCollection extends Collection
{
    /**
     * True when the per-block effects do not even agree on a direction: some
     * blocks saw the candidate faster, others slower. Such a cell's pooled
     * delta describes noise or an order effect, not the implementation.
     */
    public function effectsDisagree(): bool
    {
        $directions = Set::create(array_map(
            fn (BlockComparison $block): Ordering => Ordering::between(left: $block->wallDeltaPct(), right: 0.0),
            $this->getItems(),
        ));

        return $directions->contains(Ordering::Less) && $directions->contains(Ordering::Greater);
    }
}
