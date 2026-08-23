<?php

declare(strict_types=1);

namespace Dan\Harness\Tests\Comparison;

use Dan\Harness\Comparison\SqlNormalizer;
use Dan\Harness\Measurement\Result\SampleCollection;
use Dan\Harness\Measurement\Result\Statistics;
use Dan\Harness\Measurement\Scheduling\BlockScheduler;
use Dan\Harness\Measurement\Scheduling\RunSlot;
use Dan\Harness\Protocol\DatabaseTarget;
use Dan\Harness\Protocol\Engine;
use Dan\Harness\RunStore\Artifact\CellResult;
use Dan\Harness\RunStore\Artifact\StatementProfile;
use Dan\Harness\RunStore\Artifact\StatementProfileCollection;
use Dan\Lib\Protocol\ScenarioName;
use Dan\Lib\Protocol\Tier;
use PHPUnit\Framework\TestCase;

/**
 * Property-style tests for the invariants DAN's trustworthiness rests on.
 * These sweep input spaces instead of checking single examples, because
 * consumers of DAN reports implicitly rely on the properties, not the
 * examples.
 */
final class InvariantsTest extends TestCase
{
    public function testNormalizerIsIdempotent(): void
    {
        $normalizer = new SqlNormalizer();
        $samples = [
            'SELECT id FROM product WHERE id IN (?, ?, ?)',
            "SELECT `a`.`b`\n  FROM t WHERE x IN (:p1, :p2) AND y IN (?,?)",
            'UPDATE t SET a = ?   WHERE b IN (?, ?, ?, ?, ?, ?, ?)',
            'SELECT 1',
        ];

        foreach ($samples as $sql) {
            $once = $normalizer->normalize($sql);
            self::assertSame($once, $normalizer->normalize($once), 'Normalizer must be idempotent for: ' . $sql);
        }
    }

    public function testSchedulerGivesEveryImplementationExactlyTheRequestedIterationsForAnyBlockCount(): void
    {
        $scheduler = new BlockScheduler();

        foreach (
            [
                1,
                2,
            ] as $implementationCount
        ) {
            $slots = array_slice(RunSlot::cases(), 0, $implementationCount);
            foreach (
                [
                    1,
                    7,
                    30,
                    100,
                ] as $iterations
            ) {
                foreach (range(1, min($iterations, 10)) as $blocks) {
                    $totals = [];
                    foreach ($scheduler->schedule(slots: $slots, totalIterations: $iterations, blocks: $blocks) as $block) {
                        $slot = $block->slot->value;
                        $totals[$slot] = ($totals[$slot] ?? 0) + $block->iterations;
                    }
                    foreach ($slots as $slot) {
                        self::assertSame(
                            $iterations,
                            $totals[$slot->value] ?? null,
                            sprintf('%d impls, %d iterations, %d blocks', $implementationCount, $iterations, $blocks),
                        );
                    }
                }
            }
        }
    }

    public function testCellResultMergeAccumulatesAllSamplesRegardlessOfBlockOrder(): void
    {
        $blockA = $this->cellResult(wallSamples: [
            1_000_000,
            2_000_000,
        ], statementSamples: [
            10_000_000,
            20_000_000,
        ]);
        $blockB = $this->cellResult(wallSamples: [3_000_000], statementSamples: [30_000_000]);

        $ab = $blockA->merge($blockB);
        $ba = $blockB->merge($blockA);

        // Sample multisets must match in both merge orders - medians and
        // percentiles are order-independent.
        $sortedWallAb = $ab->wallSamples->toNsArray();
        $sortedWallBa = $ba->wallSamples->toNsArray();
        sort($sortedWallAb);
        sort($sortedWallBa);
        self::assertSame($sortedWallAb, $sortedWallBa);
        self::assertSame(
            Statistics::create($ab->wallSamples)->median()->toNsFloat(),
            Statistics::create($ba->wallSamples)->median()->toNsFloat(),
        );

        $statementAb = $ab->statements[0]->durationSamples->toNsArray();
        $statementBa = $ba->statements[0]->durationSamples->toNsArray();
        sort($statementAb);
        sort($statementBa);
        self::assertSame($statementAb, $statementBa);
    }

    public function testMergingDifferentStatementSequencesFlagsDivergence(): void
    {
        $blockA = $this->cellResult(wallSamples: [1_000_000], statementSamples: [10_000_000], sql: 'SELECT a FROM t');
        $blockB = $this->cellResult(wallSamples: [2_000_000], statementSamples: [20_000_000], sql: 'SELECT b FROM t');

        $merged = $blockA->merge($blockB);

        self::assertTrue($merged->statements[0]->divergent);
    }

    public function testPercentileUsesLinearInterpolationBetweenClosestRanks(): void
    {
        // Pins the percentile definition (R-7, the numpy/Excel default) so a
        // change in the underlying statistics library cannot silently shift
        // reported p95 values between DAN versions.
        self::assertEqualsWithDelta(9.55, $this->statistics(range(1, 10))->percentile(Statistics::P95)->toNsFloat(), 1e-9);
        self::assertSame(2.5, $this->statistics([
            1,
            2,
            3,
            4,
        ])->median()->toNsFloat());
        self::assertSame(3.0, $this->statistics([
            1,
            3,
            7,
        ])->median()->toNsFloat());
    }

    public function testMedianAndPercentileAreOrderIndependent(): void
    {
        $samples = [
            5.0,
            1.0,
            9.0,
            3.0,
            7.0,
            2.0,
        ];
        $shuffled = [
            9.0,
            2.0,
            5.0,
            7.0,
            1.0,
            3.0,
        ];

        $statistics = $this->statistics($samples);
        $shuffledStatistics = $this->statistics($shuffled);

        self::assertSame($statistics->median()->toNsFloat(), $shuffledStatistics->median()->toNsFloat());
        self::assertSame(
            $statistics->percentile(Statistics::P95)->toNsFloat(),
            $shuffledStatistics->percentile(Statistics::P95)->toNsFloat(),
        );
    }

    /**
     * @param list<int|float> $samples
     */
    private function statistics(array $samples): Statistics
    {
        return Statistics::create(SampleCollection::fromArray($samples));
    }

    /**
     * @param list<int> $wallSamples integer nanoseconds
     * @param list<int> $statementSamples integer nanoseconds
     */
    private function cellResult(array $wallSamples, array $statementSamples, string $sql = 'SELECT 1'): CellResult
    {
        return new CellResult(
            scenario: ScenarioName::fromString('scenario'),
            tier: Tier::S,
            database: new DatabaseTarget(engine: Engine::MySql, version: '8.0'),
            wallSamples: SampleCollection::fromArray($wallSamples),
            statements: StatementProfileCollection::create([
                new StatementProfile(index: 0, sql: $sql, durationSamples: SampleCollection::fromArray($statementSamples), divergent: false),
            ]),
        );
    }
}
