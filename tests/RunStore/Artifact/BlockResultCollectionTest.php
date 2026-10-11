<?php

declare(strict_types=1);

namespace Dan\Harness\Tests\RunStore\Artifact;

use Dan\Harness\Measurement\Result\SampleCollection;
use Dan\Harness\RunStore\Artifact\BlockResult;
use Dan\Harness\RunStore\Artifact\BlockResultCollection;
use Dan\Harness\RunStore\Artifact\StatementProfileCollection;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class BlockResultCollectionTest extends TestCase
{
    public function testKeepsBlocksInExecutionOrderWhateverOrderTheyArriveIn(): void
    {
        $blocks = BlockResultCollection::inExecutionOrder([
            self::block(blockIndex: 1, executionOrder: 3),
            self::block(blockIndex: 0, executionOrder: 0),
            self::block(blockIndex: 2, executionOrder: 4),
        ]);

        self::assertSame([
            0,
            3,
            4,
        ], array_map(fn (BlockResult $block): int => $block->executionOrder, $blocks->getItems()));
    }

    public function testRefusesABlockRecordedTwice(): void
    {
        try {
            BlockResultCollection::inExecutionOrder([
                self::block(blockIndex: 1, executionOrder: 3),
                self::block(blockIndex: 0, executionOrder: 0),
                self::block(blockIndex: 1, executionOrder: 3),
            ]);
        } catch (RuntimeException $refusal) {
            self::assertStringContainsString('execution position 3 was recorded twice', $refusal->getMessage());

            return;
        }

        self::fail('A block recorded twice was accepted.');
    }

    public function testFindsABlockByItsBlockIndexRatherThanItsPosition(): void
    {
        $blocks = BlockResultCollection::inExecutionOrder([
            self::block(blockIndex: 2, executionOrder: 1),
            self::block(blockIndex: 5, executionOrder: 6),
        ]);

        self::assertSame(6, $blocks->findByBlockIndex(5)?->executionOrder);
        self::assertSame(1, $blocks->findByBlockIndex(2)?->executionOrder);
        self::assertNull($blocks->findByBlockIndex(0));
    }

    private static function block(int $blockIndex, int $executionOrder): BlockResult
    {
        return new BlockResult(
            blockIndex: $blockIndex,
            executionOrder: $executionOrder,
            warmupIterations: 0,
            wallSamples: SampleCollection::fromArray([1_000]),
            statements: StatementProfileCollection::create([]),
        );
    }
}
