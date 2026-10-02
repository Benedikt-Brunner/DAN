<?php

declare(strict_types=1);

namespace Dan\Harness\Tests\RunStore\Artifact;

use Dan\Harness\Measurement\Result\SampleCollection;
use Dan\Harness\RunStore\Artifact\BlockResult;
use Dan\Harness\RunStore\Artifact\BlockResultCollection;
use Dan\Harness\RunStore\Artifact\CellResult;
use Dan\Harness\RunStore\Artifact\CellResultSchemaVersion;
use Dan\Harness\RunStore\Artifact\StatementProfile;
use Dan\Harness\RunStore\Artifact\StatementProfileCollection;
use Dan\Harness\Tests\DomainGenerators;
use Dan\Harness\Tests\PropertyTestCase;
use Eris\Generator;
use RuntimeException;

/**
 * Cell artifacts must survive JSON transport bit-for-bit, and merging block
 * results must be a union of blocks independent of arrival order - block
 * membership is the experimental design of a session, and the pooled
 * medians and percentiles are derived from it, so any order dependence or
 * lost block identity would make reported latencies depend on scheduling.
 */
final class CellResultPropertyTest extends PropertyTestCase
{
    public function testCellResultSurvivesJsonTransport(): void
    {
        $this->forAll(DomainGenerators::cellResult())->then(function (CellResult $cell): void {
            $decoded = json_decode(json_encode($cell->toArray(), \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR);
            self::assertIsArray($decoded);

            self::assertSame($cell->toArray(), CellResult::fromDecodedArray($decoded)->toArray());
        });
    }

    public function testMergeUnionsBlocksInAnyOrderWithoutLosingTheirIdentity(): void
    {
        $this->forAll(
            DomainGenerators::cellResult(),
            Generator\choose(0, 1000),
        )->then(function (CellResult $cell, int $splitSeed): void {
            // Split the cell's blocks into two partial results - the way
            // block results arrive one dan:execute at a time - and merge
            // them in both orders.
            $blocks = $cell->blocks->getItems();
            $split = $splitSeed % (count($blocks) + 1);
            $first = self::withBlocks(cell: $cell, blocks: array_slice($blocks, 0, $split));
            $second = self::withBlocks(cell: $cell, blocks: array_slice($blocks, $split));

            $ab = $first->merge($second);
            $ba = $second->merge($first);

            self::assertSame($cell->toArray(), $ab->toArray(), 'Merging the parts must rebuild the whole cell, block identity included.');
            self::assertSame($cell->toArray(), $ba->toArray(), 'Merge order must not matter.');

            $expectedWall = [];
            foreach ($cell->blocks as $block) {
                $expectedWall = [
                    ...$expectedWall,
                    ...$block->wallSamples->toNsArray(),
                ];
            }
            self::assertSame($expectedWall, $ab->wallSamples()->toNsArray(), 'Pooled samples follow execution order.');
        });
    }

    public function testBlocksAreOrderedByExecutionPositionWhateverTheirInputOrder(): void
    {
        $this->forAll(DomainGenerators::cellResult())->then(function (CellResult $cell): void {
            $reversed = BlockResultCollection::inExecutionOrder(array_reverse($cell->blocks->getItems()));

            self::assertSame($cell->blocks->toArray(), $reversed->toArray());
            $orders = array_map(fn (BlockResult $block): int => $block->executionOrder, $reversed->getItems());
            $sorted = $orders;
            sort($sorted);
            self::assertSame($sorted, $orders);
        });
    }

    public function testMergeRefusesABlockRecordedTwice(): void
    {
        $this->forAll(DomainGenerators::cellResult())->then(function (CellResult $cell): void {
            // fail() must stay outside the try: AssertionFailedError extends
            // RuntimeException, so inside it the catch would swallow the
            // failure and an accepted merge could never fail the property.
            try {
                $cell->merge($cell);
            } catch (RuntimeException) {
                // Refused - exactly what the property demands. A plain
                // expectException would end the test after the first
                // iteration and silently skip every other generated case.
                $this->addToAssertionCount(1);

                return;
            }

            self::fail('Merging a cell with itself duplicated its blocks instead of being refused.');
        });
    }

    public function testPooledStatementsAccumulateEveryBlocksDurationSamples(): void
    {
        $this->forAll(DomainGenerators::cellResult())->then(function (CellResult $cell): void {
            $pooled = $cell->statements();

            self::assertCount(count($cell->blocks[0]->statements), $pooled);
            foreach ($pooled as $index => $statement) {
                $expected = [];
                foreach ($cell->blocks as $block) {
                    $expected = [
                        ...$expected,
                        ...$block->statements[$index]->durationSamples->toNsArray(),
                    ];
                }
                self::assertSame($expected, $statement->durationSamples->toNsArray());
                self::assertSame($cell->blocks[0]->statements[$index]->sql, $statement->sql);
            }
        });
    }

    public function testPoolingIdenticalStatementSequencesNeverFlagsDivergence(): void
    {
        $this->forAll(DomainGenerators::cellResult())->then(function (CellResult $cell): void {
            foreach ($cell->statements() as $index => $statement) {
                self::assertSame(
                    $cell->blocks[0]->statements[$index]->divergent,
                    $statement->divergent,
                    'Pooling identical SQL across blocks must never introduce divergence.',
                );
            }
        });
    }

    public function testABlockWithDifferentSqlAtAnyPositionFlagsExactlyThatStatement(): void
    {
        $this->forAll(
            DomainGenerators::cellResult(),
            Generator\choose(0, 1000),
        )->then(function (CellResult $cell, int $positionSeed): void {
            // Any position, not just the last - a merge that only compares
            // the tail of the sequence must fail here.
            $statements = $cell->blocks[0]->statements;
            $position = $positionSeed % count($statements);
            $changed = [];
            foreach ($statements as $index => $statement) {
                $changed[] = new StatementProfile(
                    index: $statement->index,
                    sql: $index === $position ? $statement->sql . ' /* changed */' : $statement->sql,
                    durationSamples: $statement->durationSamples,
                    divergent: $statement->divergent,
                );
            }
            $laterBlock = self::withBlocks(cell: $cell, blocks: [self::blockAfter(cell: $cell, statements: StatementProfileCollection::create($changed))]);

            $merged = $cell->merge($laterBlock);

            $pooled = $merged->statements();
            self::assertTrue($pooled[$position]->divergent);
            // The earliest block's SQL wins; averaging apples and oranges is
            // exactly what the flag prevents.
            self::assertSame($statements[$position]->sql, $pooled[$position]->sql);
            foreach ($pooled as $index => $statement) {
                if ($index !== $position) {
                    self::assertSame($cell->statements()[$index]->divergent, $statement->divergent);
                }
            }
        });
    }

    public function testDivergenceFromAnyBlockStaysSticky(): void
    {
        // A statement flagged divergent in ANY block must stay flagged in the
        // pooled view, whichever block carries the flag.
        $this->forAll(DomainGenerators::cellResult(), Generator\bool())->then(function (CellResult $cell, bool $flagOnLaterBlock): void {
            $unflagged = self::withAllDivergentFlags(cell: $cell, divergent: false);
            $laterBlock = self::blockAfter(cell: $cell, statements: self::statementsWithDivergentFlags(statements: $cell->blocks[0]->statements, divergent: $flagOnLaterBlock));
            $earlier = $flagOnLaterBlock ? $unflagged : self::withAllDivergentFlags(cell: $cell, divergent: true);

            $merged = $earlier->merge(self::withBlocks(cell: $cell, blocks: [$laterBlock]));

            foreach ($merged->statements() as $statement) {
                self::assertTrue($statement->divergent);
            }
        });
    }

    public function testRefusesTypeCorruptedPayloads(): void
    {
        // Every runtime validation in the decode path must actually fire:
        // one wrongly-typed field anywhere in the payload - including inside
        // nested blocks, statements and the database target - must be
        // refused, never silently coerced into a differently-shaped cell.
        $corruptions = [
            [
                ['schemaVersion'],
                'one',
            ],
            [
                ['scenario'],
                7,
            ],
            [
                ['tier'],
                123,
            ],
            [
                ['tier'],
                'XXL',
            ],
            [
                ['database'],
                'mysql-8.0',
            ],
            [
                [
                    'database',
                    'engine',
                ],
                5,
            ],
            [
                [
                    'database',
                    'version',
                ],
                9,
            ],
            [
                ['blocks'],
                'none',
            ],
            [
                [
                    'blocks',
                    0,
                ],
                'block',
            ],
            [
                [
                    'blocks',
                    0,
                    'blockIndex',
                ],
                'first',
            ],
            [
                [
                    'blocks',
                    0,
                    'executionOrder',
                ],
                1.5,
            ],
            [
                [
                    'blocks',
                    0,
                    'warmupIterations',
                ],
                'two',
            ],
            [
                [
                    'blocks',
                    0,
                    'wallNsSamples',
                ],
                'none',
            ],
            [
                [
                    'blocks',
                    0,
                    'wallNsSamples',
                    0,
                ],
                'fast',
            ],
            [
                [
                    'blocks',
                    0,
                    'statements',
                ],
                'none',
            ],
            [
                [
                    'blocks',
                    0,
                    'statements',
                    0,
                ],
                'statement',
            ],
            [
                [
                    'blocks',
                    0,
                    'statements',
                    0,
                    'index',
                ],
                'first',
            ],
            [
                [
                    'blocks',
                    0,
                    'statements',
                    0,
                    'sql',
                ],
                42,
            ],
            [
                [
                    'blocks',
                    0,
                    'statements',
                    0,
                    'durationsNsSamples',
                ],
                'quick',
            ],
            [
                [
                    'blocks',
                    0,
                    'statements',
                    0,
                    'durationsNsSamples',
                    0,
                ],
                'slow',
            ],
            [
                [
                    'blocks',
                    0,
                    'statements',
                    0,
                    'divergent',
                ],
                'yes',
            ],
        ];

        $this->forAll(
            DomainGenerators::cellResult(),
            Generator\elements(...$corruptions),
        )->then(function (CellResult $cell, mixed $corruption): void {
            $parts = DomainGenerators::asList($corruption);
            $path = DomainGenerators::asPath($parts[0]);
            $payload = DomainGenerators::corruptedAt(
                payload: $cell->toArray(),
                path: $path,
                junk: $parts[1],
            );

            // fail() must stay outside the try: AssertionFailedError extends
            // RuntimeException, so inside it the catch would swallow the
            // failure and an accepted payload could never fail the property.
            try {
                CellResult::fromDecodedArray($payload);
            } catch (RuntimeException) {
                // Refused - exactly what the property demands. A plain
                // expectException would end the test after the first
                // iteration and silently skip every other generated case.
                $this->addToAssertionCount(1);

                return;
            }

            self::fail(sprintf('Corrupting "%s" was accepted.', implode('.', $path)));
        });
    }

    public function testRefusesEveryForeignSchemaVersion(): void
    {
        $this->forAll(
            DomainGenerators::cellResult(),
            Generator\suchThat(
                fn (int $version): bool => $version !== CellResultSchemaVersion::getCurrent()->value,
                Generator\choose(-1000, 1000),
            ),
        )->then(function (CellResult $cell, int $foreignVersion): void {
            $payload = $cell->toArray();
            $payload['schemaVersion'] = $foreignVersion;

            // fail() must stay outside the try: AssertionFailedError extends
            // RuntimeException, so inside it the catch would swallow the
            // failure and an accepted payload could never fail the property.
            try {
                CellResult::fromDecodedArray($payload);
            } catch (RuntimeException) {
                // Refused - exactly what the property demands. A plain
                // expectException would end the test after the first
                // iteration and silently skip every other generated case.
                $this->addToAssertionCount(1);

                return;
            }

            self::fail(sprintf('Schema version %d was accepted.', $foreignVersion));
        });
    }

    /**
     * @param list<BlockResult> $blocks
     */
    private static function withBlocks(CellResult $cell, array $blocks): CellResult
    {
        return new CellResult(
            scenario: $cell->scenario,
            tier: $cell->tier,
            database: $cell->database,
            blocks: BlockResultCollection::inExecutionOrder($blocks),
        );
    }

    /**
     * A new block scheduled after every block the cell already has, carrying
     * the given statement sequence and the first block's wall samples.
     */
    private static function blockAfter(CellResult $cell, StatementProfileCollection $statements): BlockResult
    {
        $lastBlock = $cell->blocks[count($cell->blocks) - 1];

        return new BlockResult(
            blockIndex: $lastBlock->blockIndex + 1,
            executionOrder: $lastBlock->executionOrder + 2,
            warmupIterations: $lastBlock->warmupIterations,
            wallSamples: SampleCollection::fromArray($cell->blocks[0]->wallSamples->toNsArray()),
            statements: $statements,
        );
    }

    private static function withAllDivergentFlags(CellResult $cell, bool $divergent): CellResult
    {
        $blocks = [];
        foreach ($cell->blocks as $block) {
            $blocks[] = new BlockResult(
                blockIndex: $block->blockIndex,
                executionOrder: $block->executionOrder,
                warmupIterations: $block->warmupIterations,
                wallSamples: $block->wallSamples,
                statements: self::statementsWithDivergentFlags(statements: $block->statements, divergent: $divergent),
            );
        }

        return self::withBlocks(cell: $cell, blocks: $blocks);
    }

    private static function statementsWithDivergentFlags(StatementProfileCollection $statements, bool $divergent): StatementProfileCollection
    {
        $flagged = [];
        foreach ($statements as $statement) {
            $flagged[] = new StatementProfile(
                index: $statement->index,
                sql: $statement->sql,
                durationSamples: $statement->durationSamples,
                divergent: $divergent,
            );
        }

        return StatementProfileCollection::create($flagged);
    }
}
