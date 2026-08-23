<?php

declare(strict_types=1);

namespace Dan\Harness\Tests\RunStore\Artifact;

use Dan\Harness\Measurement\Result\SampleCollection;
use Dan\Harness\Measurement\Result\Statistics;
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
 * results must accumulate sample multisets independent of block order -
 * medians and percentiles are computed from the merged samples, so any
 * order dependence would make reported latencies depend on scheduling.
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

    public function testMergeAccumulatesSampleMultisetsInAnyOrder(): void
    {
        $this->forAll(
            DomainGenerators::cellResult(),
            DomainGenerators::samples(),
        )->then(function (CellResult $blockA, mixed $otherWallSamples): void {
            $otherWallSamples = DomainGenerators::asIntList($otherWallSamples);
            $blockB = new CellResult(
                scenario: $blockA->scenario,
                tier: $blockA->tier,
                database: $blockA->database,
                wallSamples: SampleCollection::fromArray($otherWallSamples),
                statements: $blockA->statements,
            );

            $ab = $blockA->merge($blockB);
            $ba = $blockB->merge($blockA);

            $abWall = $ab->wallSamples->toNsArray();
            $baWall = $ba->wallSamples->toNsArray();
            self::assertCount(count($blockA->wallSamples) + count($otherWallSamples), $abWall);
            sort($abWall);
            sort($baWall);
            self::assertSame($abWall, $baWall);
            self::assertSame(
                Statistics::create($ab->wallSamples)->median()->toNsFloat(),
                Statistics::create($ba->wallSamples)->median()->toNsFloat(),
            );

            foreach ($ab->statements as $index => $statement) {
                $abDurations = $statement->durationSamples->toNsArray();
                $baDurations = $ba->statements[$index]->durationSamples->toNsArray();
                sort($abDurations);
                sort($baDurations);
                self::assertSame($abDurations, $baDurations);
            }
        });
    }

    public function testMergingIdenticalStatementSequencesNeverFlagsDivergence(): void
    {
        $this->forAll(DomainGenerators::cellResult())->then(function (CellResult $cell): void {
            $merged = $cell->merge($cell);

            foreach ($merged->statements as $index => $statement) {
                self::assertSame(
                    $cell->statements[$index]->divergent,
                    $statement->divergent,
                    'Merging identical SQL must never introduce divergence.',
                );
            }
        });
    }

    public function testMergingDifferentSqlAtAnyPositionFlagsExactlyThatStatement(): void
    {
        $this->forAll(DomainGenerators::cellResult())->then(function (CellResult $cell): void {
            $position = count($cell->statements) - 1;
            $changed = [];
            foreach ($cell->statements as $index => $statement) {
                $changed[] = new StatementProfile(
                    index: $statement->index,
                    sql: $index === $position ? $statement->sql . ' /* changed */' : $statement->sql,
                    durationSamples: $statement->durationSamples,
                    divergent: $statement->divergent,
                );
            }
            $other = new CellResult(
                scenario: $cell->scenario,
                tier: $cell->tier,
                database: $cell->database,
                wallSamples: $cell->wallSamples,
                statements: StatementProfileCollection::create($changed),
            );

            $merged = $cell->merge($other);

            self::assertTrue($merged->statements[$position]->divergent);
            // The first block's SQL wins; averaging apples and oranges is
            // exactly what the flag prevents.
            self::assertSame($cell->statements[$position]->sql, $merged->statements[$position]->sql);
            foreach ($merged->statements as $index => $statement) {
                if ($index !== $position) {
                    self::assertSame($cell->statements[$index]->divergent, $statement->divergent);
                }
            }
        });
    }

    public function testRefusesTypeCorruptedPayloads(): void
    {
        // Every runtime validation in the decode path must actually fire:
        // one wrongly-typed field anywhere in the payload - including inside
        // nested statements and the database target - must be refused, never
        // silently coerced into a differently-shaped cell.
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
                ['wallNsSamples'],
                'none',
            ],
            [
                [
                    'wallNsSamples',
                    0,
                ],
                'fast',
            ],
            [
                ['statements'],
                'none',
            ],
            [
                [
                    'statements',
                    0,
                ],
                'statement',
            ],
            [
                [
                    'statements',
                    0,
                    'index',
                ],
                'first',
            ],
            [
                [
                    'statements',
                    0,
                    'sql',
                ],
                42,
            ],
            [
                [
                    'statements',
                    0,
                    'durationsNsSamples',
                ],
                'quick',
            ],
            [
                [
                    'statements',
                    0,
                    'durationsNsSamples',
                    0,
                ],
                'slow',
            ],
            [
                [
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
            $payload = DomainGenerators::corruptedAt(
                payload: $cell->toArray(),
                path: DomainGenerators::asPath($parts[0]),
                junk: $parts[1],
            );

            try {
                CellResult::fromDecodedArray($payload);
                self::fail('The malformed input was accepted.');
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
                // Refused - exactly what the property demands. A plain
                // expectException would end the test after the first
                // iteration and silently skip every other generated case.
            }
        });
    }

    public function testDivergenceFromEitherBlockStaysSticky(): void
    {
        // A statement flagged divergent in ANY merged block must stay
        // flagged, whichever side carries the flag.
        $this->forAll(DomainGenerators::cellResult(), Generator\bool())->then(function (CellResult $cell, bool $flagOnOther): void {
            $unflagged = $this->withAllDivergentFlags(cell: $cell, divergent: false);
            $flagged = $this->withAllDivergentFlags(cell: $cell, divergent: true);

            $merged = $flagOnOther ? $unflagged->merge($flagged) : $flagged->merge($unflagged);

            foreach ($merged->statements as $statement) {
                self::assertTrue($statement->divergent);
            }
        });
    }

    private function withAllDivergentFlags(CellResult $cell, bool $divergent): CellResult
    {
        $statements = [];
        foreach ($cell->statements as $statement) {
            $statements[] = new StatementProfile(
                index: $statement->index,
                sql: $statement->sql,
                durationSamples: $statement->durationSamples,
                divergent: $divergent,
            );
        }

        return new CellResult(
            scenario: $cell->scenario,
            tier: $cell->tier,
            database: $cell->database,
            wallSamples: $cell->wallSamples,
            statements: StatementProfileCollection::create($statements),
        );
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

            try {
                CellResult::fromDecodedArray($payload);
                self::fail('The malformed input was accepted.');
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
                // Refused - exactly what the property demands. A plain
                // expectException would end the test after the first
                // iteration and silently skip every other generated case.
            }
        });
    }
}
