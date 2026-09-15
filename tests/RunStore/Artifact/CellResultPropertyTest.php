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
use Dan\Lib\Protocol\ResultSet;
use Dan\Lib\Protocol\StatementDivergence;
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

    public function testPoolingIdenticalStatementSequencesOnlyCarriesTheBlocksOwnDivergence(): void
    {
        $this->forAll(DomainGenerators::cellResult())->then(function (CellResult $cell): void {
            foreach ($cell->statements() as $index => $statement) {
                $textDiffers = false;
                $intermittent = false;
                $observed = 0;
                foreach ($cell->blocks as $block) {
                    $textDiffers = $textDiffers || $block->statements[$index]->divergence->includesText();
                    $intermittent = $intermittent || $block->statements[$index]->divergence->includesPresence();
                    $observed += $block->statements[$index]->observed;
                }
                self::assertSame(
                    StatementDivergence::fromFlags(textDiffers: $textDiffers, intermittent: $intermittent),
                    $statement->divergence,
                    'Pooling identical SQL across blocks must neither introduce nor lose divergence.',
                );
                self::assertSame($observed, $statement->observed);
            }
        });
    }

    public function testAPositionMissingFromALaterBlockIsIntermittentInThePooledView(): void
    {
        $this->forAll(DomainGenerators::cellResult())->then(function (CellResult $cell): void {
            // A later block that never produced the last position at all: its
            // own profile cannot say so (there is nothing to record), only
            // the pooled view can - by counting observations against the
            // pooled iterations.
            $statements = $cell->blocks[0]->statements;
            $last = count($statements) - 1;
            $truncated = StatementProfileCollection::create(array_slice($statements->getItems(), 0, $last));
            $laterBlock = self::blockAfter(cell: $cell, statements: $truncated);

            $merged = $cell->merge(self::withBlocks(cell: $cell, blocks: [$laterBlock]));

            $pooled = $merged->statements();
            self::assertCount(count($statements), $pooled);
            self::assertTrue($pooled[$last]->divergence->includesPresence());
            self::assertSame($cell->statements()[$last]->observed, $pooled[$last]->observed);
            self::assertLessThan($merged->wallSamples()->count(), $pooled[$last]->observed);
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
                    observed: $statement->observed,
                    divergence: $statement->divergence,
                    plan: $statement->plan,
                );
            }
            $laterBlock = self::withBlocks(cell: $cell, blocks: [self::blockAfter(cell: $cell, statements: StatementProfileCollection::create($changed))]);

            $merged = $cell->merge($laterBlock);

            $pooled = $merged->statements();
            self::assertTrue($pooled[$position]->divergence->includesText());
            // The earliest block's SQL wins; averaging apples and oranges is
            // exactly what the flag prevents.
            self::assertSame($statements[$position]->sql, $pooled[$position]->sql);
            foreach ($pooled as $index => $statement) {
                if ($index !== $position) {
                    self::assertSame($cell->statements()[$index]->divergence->includesText(), $statement->divergence->includesText());
                }
            }
        });
    }

    public function testTextDivergenceFromAnyBlockStaysSticky(): void
    {
        // A position whose SQL varied in ANY block stays text-divergent in
        // the pooled view, whichever block carries the flag.
        $this->forAll(DomainGenerators::cellResult(), Generator\bool())->then(function (CellResult $cell, bool $flagOnLaterBlock): void {
            $unflagged = self::withTextDivergence(cell: $cell, textDiffers: false);
            $laterBlock = self::blockAfter(cell: $cell, statements: self::statementsWithTextDivergence(statements: $cell->blocks[0]->statements, textDiffers: $flagOnLaterBlock));
            $earlier = $flagOnLaterBlock ? $unflagged : self::withTextDivergence(cell: $cell, textDiffers: true);

            $merged = $earlier->merge(self::withBlocks(cell: $cell, blocks: [$laterBlock]));

            foreach ($merged->statements() as $statement) {
                self::assertTrue($statement->divergence->includesText());
            }
        });
    }

    public function testTheCellResultIsConsistentOnlyWhenEveryBlockAgreesInEveryIteration(): void
    {
        $this->forAll(DomainGenerators::cellResult())->then(function (CellResult $cell): void {
            $everyBlockConsistent = true;
            foreach ($cell->blocks as $block) {
                $everyBlockConsistent = $everyBlockConsistent && $block->resultSetConsistent;
                self::assertTrue($block->resultSet->equals($cell->resultSet()), 'Generated blocks share the cell result set.');
            }

            self::assertSame($everyBlockConsistent, $cell->resultSetConsistent());
        });
    }

    public function testABlockReturningADifferentResultMakesTheCellInconsistent(): void
    {
        $this->forAll(DomainGenerators::cellResult(), DomainGenerators::resultSet())->then(function (CellResult $cell, mixed $otherResult): void {
            $otherResult = DomainGenerators::asResultSet($otherResult);
            if ($otherResult->equals($cell->resultSet())) {
                // Nothing to test on this draw; the property is about differing results.
                $this->addToAssertionCount(1);

                return;
            }
            $laterBlock = self::blockAfter(cell: $cell, statements: $cell->blocks[0]->statements, resultSet: $otherResult);

            $merged = $cell->merge(self::withBlocks(cell: $cell, blocks: [$laterBlock]));

            self::assertFalse($merged->resultSetConsistent());
            self::assertTrue($merged->resultSet()->equals($cell->resultSet()), 'The earliest block names the recorded result.');
        });
    }

    public function testACorruptedPlanCaptureIsRefused(): void
    {
        $this->forAll(DomainGenerators::cellResult())->then(function (CellResult $cell): void {
            $payload = $cell->toArray();
            $payload['blocks'][0]['statements'][0]['plan'] = [
                'capture' => 'maybe',
                'raw' => null,
            ];

            try {
                CellResult::fromDecodedArray($payload);
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);

                return;
            }

            self::fail('An unknown plan capture outcome was accepted.');
        });
    }

    public function testPooledStatementsKeepTheFirstCapturedPlan(): void
    {
        $this->forAll(DomainGenerators::cellResult())->then(function (CellResult $cell): void {
            foreach ($cell->statements() as $index => $statement) {
                $expected = null;
                foreach ($cell->blocks as $block) {
                    $expected ??= $block->statements[$index]->plan;
                }
                self::assertSame($expected?->toArray(), $statement->plan?->toArray());
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
                    'resultSet',
                ],
                'none',
            ],
            [
                [
                    'blocks',
                    0,
                    'resultSet',
                    'ids',
                ],
                'abc',
            ],
            [
                [
                    'blocks',
                    0,
                    'resultSet',
                    'total',
                ],
                '2',
            ],
            [
                [
                    'blocks',
                    0,
                    'resultSetConsistent',
                ],
                'yes',
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
                    'observed',
                ],
                'all',
            ],
            [
                [
                    'blocks',
                    0,
                    'statements',
                    0,
                    'divergence',
                ],
                true,
            ],
            [
                [
                    'blocks',
                    0,
                    'statements',
                    0,
                    'divergence',
                ],
                'sometimes',
            ],
            [
                [
                    'blocks',
                    0,
                    'statements',
                    0,
                    'plan',
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
    private static function blockAfter(CellResult $cell, StatementProfileCollection $statements, ?ResultSet $resultSet = null): BlockResult
    {
        $lastBlock = $cell->blocks[count($cell->blocks) - 1];

        return new BlockResult(
            blockIndex: $lastBlock->blockIndex + 1,
            executionOrder: $lastBlock->executionOrder + 2,
            warmupIterations: $lastBlock->warmupIterations,
            resultSet: $resultSet ?? $lastBlock->resultSet,
            resultSetConsistent: true,
            wallSamples: SampleCollection::fromArray($cell->blocks[0]->wallSamples->toNsArray()),
            statements: $statements,
        );
    }

    private static function withTextDivergence(CellResult $cell, bool $textDiffers): CellResult
    {
        $blocks = [];
        foreach ($cell->blocks as $block) {
            $blocks[] = new BlockResult(
                blockIndex: $block->blockIndex,
                executionOrder: $block->executionOrder,
                warmupIterations: $block->warmupIterations,
                resultSet: $block->resultSet,
                resultSetConsistent: $block->resultSetConsistent,
                wallSamples: $block->wallSamples,
                statements: self::statementsWithTextDivergence(statements: $block->statements, textDiffers: $textDiffers),
            );
        }

        return self::withBlocks(cell: $cell, blocks: $blocks);
    }

    /**
     * Sets the text flag on every position, keeping each position's own
     * presence divergence.
     */
    private static function statementsWithTextDivergence(StatementProfileCollection $statements, bool $textDiffers): StatementProfileCollection
    {
        $flagged = [];
        foreach ($statements as $statement) {
            $flagged[] = new StatementProfile(
                index: $statement->index,
                sql: $statement->sql,
                durationSamples: $statement->durationSamples,
                observed: $statement->observed,
                divergence: StatementDivergence::fromFlags(textDiffers: $textDiffers, intermittent: $statement->divergence->includesPresence()),
                plan: $statement->plan,
            );
        }

        return StatementProfileCollection::create($flagged);
    }
}
