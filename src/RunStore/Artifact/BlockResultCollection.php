<?php

declare(strict_types=1);

namespace Dan\Harness\RunStore\Artifact;

use Dan\Harness\Measurement\Result\SampleCollection;
use Dan\Lib\Collections\Collection;
use Dan\Lib\Protocol\ResultSet;
use RuntimeException;

/**
 * The measurement blocks of one grid cell, kept in execution order. Merging
 * is a union keyed by execution order: the same block can never be recorded
 * twice, and the result is independent of the order in which block results
 * arrive.
 *
 * @extends Collection<BlockResult>
 *
 * @phpstan-import-type BlockResultPayload from BlockResult
 */
final readonly class BlockResultCollection extends Collection
{
    /**
     * @param array<mixed> $payload
     */
    public static function fromDecodedArray(array $payload): self
    {
        if (!array_is_list($payload)) {
            throw new RuntimeException('Malformed blocks payload: expected a list.');
        }

        $blocks = [];
        foreach ($payload as $block) {
            if (!is_array($block)) {
                throw new RuntimeException('Malformed blocks payload: every entry must be an object.');
            }
            $blocks[] = BlockResult::fromDecodedArray($block);
        }

        return self::inExecutionOrder($blocks);
    }

    /**
     * @param list<BlockResult> $blocks
     */
    public static function inExecutionOrder(array $blocks): self
    {
        $byExecutionOrder = [];
        foreach ($blocks as $block) {
            if (isset($byExecutionOrder[$block->executionOrder])) {
                throw new RuntimeException(sprintf('Measurement block at execution position %d was recorded twice.', $block->executionOrder));
            }
            $byExecutionOrder[$block->executionOrder] = $block;
        }
        ksort($byExecutionOrder);

        return self::create(array_values($byExecutionOrder));
    }

    public function merge(self $other): self
    {
        return self::inExecutionOrder([
            ...$this->getItems(),
            ...$other->getItems(),
        ]);
    }

    /**
     * What the scenario returned, as recorded by the earliest block.
     */
    public function resultSet(): ResultSet
    {
        $first = $this->getItems()[0] ?? throw new RuntimeException('A cell without measurement blocks has no result set.');

        return $first->resultSet;
    }

    /**
     * True only when every block saw the same result in every one of its
     * iterations - the precondition for calling the recorded result "the"
     * result of this implementation against this dataset.
     */
    public function resultSetConsistent(): bool
    {
        $first = $this->getItems()[0] ?? null;
        if ($first === null) {
            return true;
        }
        foreach ($this as $block) {
            if (!$block->resultSetConsistent || !$block->resultSet->equals($first->resultSet)) {
                return false;
            }
        }

        return true;
    }

    /**
     * All wall samples of the cell, block by block in execution order.
     */
    public function pooledWallSamples(): SampleCollection
    {
        $pooled = SampleCollection::create([]);
        foreach ($this as $block) {
            $pooled = $pooled->merge($block->wallSamples);
        }

        return $pooled;
    }

    /**
     * The cell's statement sequence with duration samples and observations
     * pooled across blocks, position by position. SQL differing between
     * blocks is carried by the profiles' own merge; presence is judged
     * against the pooled iteration count, so a position that one block never
     * produced at all reads as intermittent in the pooled view.
     */
    public function pooledStatements(): StatementProfileCollection
    {
        $pooled = StatementProfileCollection::create([]);
        $iterations = 0;
        foreach ($this as $block) {
            $pooled = $pooled->merge($block->statements);
            $iterations += $block->iterations();
        }

        return StatementProfileCollection::create(array_map(
            fn (StatementProfile $statement): StatementProfile => $statement->withPresenceAgainst($iterations),
            $pooled->getItems(),
        ));
    }

    /** @return list<BlockResultPayload> */
    public function toArray(): array
    {
        return array_map(
            fn (BlockResult $block): array => $block->toArray(),
            $this->getItems(),
        );
    }
}
