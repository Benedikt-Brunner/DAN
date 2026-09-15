<?php

declare(strict_types=1);

namespace Dan\Harness\Tests\Comparison;

use Dan\Harness\Comparison\AlignmentKind;
use Dan\Harness\Comparison\RunComparator;
use Dan\Harness\Environment\DatabaseImage;
use Dan\Harness\Environment\DatabaseNetworkPath;
use Dan\Harness\Environment\DockerEngine;
use Dan\Harness\Environment\ExecutionEnvironment;
use Dan\Harness\Environment\HostMachine;
use Dan\Harness\Gate\Policy;
use Dan\Harness\Gate\ViolationKind;
use Dan\Harness\Implementation\Identity\Identity;
use Dan\Harness\Implementation\Reference\ReferenceType;
use Dan\Harness\Measurement\Result\SampleCollection;
use Dan\Harness\Measurement\Scheduling\RunSlot;
use Dan\Harness\Plan\QueryPlan;
use Dan\Harness\Protocol\DatabaseTarget;
use Dan\Harness\Protocol\Engine;
use Dan\Harness\Protocol\Protocol;
use Dan\Harness\RunStore\Artifact\BlockResult;
use Dan\Harness\RunStore\Artifact\BlockResultCollection;
use Dan\Harness\RunStore\Artifact\CellId;
use Dan\Harness\RunStore\Artifact\CellResult;
use Dan\Harness\RunStore\Artifact\RunManifest;
use Dan\Harness\RunStore\Artifact\StatementProfile;
use Dan\Harness\RunStore\Artifact\StatementProfileCollection;
use Dan\Harness\RunStore\Filesystem\RunDirectory;
use Dan\Lib\Filesystem\Path;
use Dan\Lib\Protocol\PlanCapture;
use Dan\Lib\Protocol\ResultSet;
use Dan\Lib\Protocol\ScenarioName;
use Dan\Lib\Protocol\StatementDivergence;
use Dan\Lib\Protocol\Tier;
use DateTimeImmutable;
use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Round-trips two runs through the on-disk store and diffs them - covers the
 * store, the comparator, and the gate together, including the A/A property:
 * a run compared with an identical run must report no SQL changes and a zero
 * latency delta.
 */
final class RunComparatorTest extends TestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        $this->workDir = sys_get_temp_dir() . '/dan-comparator-test-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->workDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            if (!$file instanceof SplFileInfo) {
                continue;
            }
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->workDir);
    }

    public function testIdenticalRunsCompareCleanlyAndPassTheGate(): void
    {
        $baseline = $this->writeRun(slot: RunSlot::Baseline, wallNs: [
            10_000_000,
            11_000_000,
            10_500_000,
        ], sql: 'SELECT `id` FROM `product` WHERE `id` IN (?, ?)');
        $candidate = $this->writeRun(slot: RunSlot::Candidate, wallNs: [
            10_000_000,
            11_000_000,
            10_500_000,
        ], sql: 'SELECT `id` FROM `product` WHERE `id` IN (?, ?, ?)');

        $comparison = RunComparator::compare(baseline: $baseline, candidate: $candidate);

        self::assertTrue($comparison->protocolsMatch);
        self::assertTrue($comparison->environmentsComparable);
        self::assertCount(1, $comparison->cells);
        // IN-list arity differences are data-shape noise, not SQL changes.
        self::assertFalse($comparison->cells[0]->sqlChanged());
        self::assertSame(0.0, $comparison->cells[0]->wallDeltaPct());

        $policy = new Policy(maxWallRegressionPct: 5.0, failOnSqlChange: true);
        self::assertSame([], $policy->evaluate($comparison->cells));
    }

    public function testStructuralSqlChangeAndRegressionAreDetectedAndGated(): void
    {
        $baseline = $this->writeRun(slot: RunSlot::Baseline, wallNs: [
            10_000_000,
            10_000_000,
            10_000_000,
        ], sql: 'SELECT `id` FROM `product`');
        $candidate = $this->writeRun(slot: RunSlot::Candidate, wallNs: [
            20_000_000,
            20_000_000,
            20_000_000,
        ], sql: 'SELECT `id` FROM `product` LEFT JOIN `x` ON 1');

        $comparison = RunComparator::compare(baseline: $baseline, candidate: $candidate);

        self::assertTrue($comparison->cells[0]->sqlChanged());
        self::assertSame([0], $comparison->cells[0]->alignment->baselineIndices(AlignmentKind::Modified));
        self::assertSame(100.0, $comparison->cells[0]->wallDeltaPct());

        $violations = (new Policy(maxWallRegressionPct: 15.0, failOnSqlChange: true))->evaluate($comparison->cells);
        self::assertCount(2, $violations);
    }

    public function testPairsBlocksByIndexAndExposesOrderEffects(): void
    {
        // Two mirrored block pairs: baseline first in block 0, candidate
        // first in block 1. The candidate is slower in block 0 and faster in
        // block 1 - a textbook order effect the pooled delta would hide.
        $baseline = $this->writeRun(slot: RunSlot::Baseline, wallNs: [
            10_000_000,
            10_000_000,
        ], sql: 'SELECT `id` FROM `product`', blocks: [
            [
                0,
                [
                    10_000_000,
                    10_000_000,
                ],
            ],
            [
                3,
                [
                    10_000_000,
                    10_000_000,
                ],
            ],
        ]);
        $candidate = $this->writeRun(slot: RunSlot::Candidate, wallNs: [
            10_000_000,
            10_000_000,
        ], sql: 'SELECT `id` FROM `product`', blocks: [
            [
                1,
                [
                    12_000_000,
                    12_000_000,
                ],
            ],
            [
                2,
                [
                    8_000_000,
                    8_000_000,
                ],
            ],
        ]);

        $cell = RunComparator::compare(baseline: $baseline, candidate: $candidate)->cells[0];

        self::assertCount(2, $cell->blocks);
        self::assertSame(0, $cell->blocks[0]->blockIndex);
        self::assertTrue($cell->blocks[0]->baselineRanFirst());
        self::assertSame(20.0, $cell->blocks[0]->wallDeltaPct());
        self::assertSame(1, $cell->blocks[1]->blockIndex);
        self::assertFalse($cell->blocks[1]->baselineRanFirst());
        self::assertSame(-20.0, $cell->blocks[1]->wallDeltaPct());
        self::assertTrue($cell->blockEffectsDisagree());
        self::assertSame(0.0, $cell->wallDeltaPct(), 'The pooled medians cancel out - exactly why the per-block view exists.');
    }

    public function testChangedStatementsCarryTheirPlansThroughTheStore(): void
    {
        $baseline = $this->writeRun(slot: RunSlot::Baseline, wallNs: [
            10_000_000,
            10_000_000,
            10_000_000,
        ], sql: 'SELECT `id` FROM `product` WHERE `id` = ?', plan: new QueryPlan(capture: PlanCapture::Captured, raw: [
            'query_block' => [
                'table' => [
                    'table_name' => 'product',
                    'access_type' => 'const',
                    'key' => 'PRIMARY',
                    'rows_examined_per_scan' => 1,
                ],
            ],
        ]));
        $candidate = $this->writeRun(slot: RunSlot::Candidate, wallNs: [
            10_000_000,
            10_000_000,
            10_000_000,
        ], sql: 'SELECT `id` FROM `product` WHERE `product_number` = ?', plan: new QueryPlan(capture: PlanCapture::Captured, raw: [
            'query_block' => [
                'table' => [
                    'table_name' => 'product',
                    'access_type' => 'ALL',
                    'rows_examined_per_scan' => 1000,
                ],
            ],
        ]));

        $cell = RunComparator::compare(baseline: $baseline, candidate: $candidate)->cells[0];

        self::assertCount(1, $cell->planChanges);
        self::assertSame(0, $cell->planChanges[0]->statement->baselineIndex);
        self::assertSame([
            'product: access const -> ALL',
            'product: index PRIMARY -> none',
            'product: ~1 -> ~1000 rows',
        ], $cell->planChanges[0]->materialChanges());
    }

    public function testADifferentResultIsACorrectnessViolationWhateverTheLatency(): void
    {
        // Same SQL shape, candidate twice as fast - and returning one row
        // fewer. The gate must fail on the result, not celebrate the speed.
        $baseline = $this->writeRun(slot: RunSlot::Baseline, wallNs: [
            10_000_000,
            10_000_000,
            10_000_000,
        ], sql: 'SELECT `id` FROM `product`', resultSet: new ResultSet(ids: [
            'a',
            'b',
        ], total: 2));
        $candidate = $this->writeRun(slot: RunSlot::Candidate, wallNs: [
            5_000_000,
            5_000_000,
            5_000_000,
        ], sql: 'SELECT `id` FROM `product`', resultSet: new ResultSet(ids: ['a'], total: 1));

        $comparison = RunComparator::compare(baseline: $baseline, candidate: $candidate);

        $cell = $comparison->cells[0];
        self::assertFalse($cell->resultSets->equivalent());
        self::assertTrue($cell->resultSets->idsDiffer());
        self::assertTrue($cell->resultSets->totalDiffers());
        self::assertFalse($cell->sqlChanged());
        $violations = (new Policy(maxWallRegressionPct: null, failOnSqlChange: false))->evaluate($comparison->cells);
        self::assertCount(1, $violations);
        self::assertSame(ViolationKind::ResultDivergence, $violations[0]->kind);
    }

    /**
     * @param list<int> $wallNs integer nanoseconds of the single block written when $blocks is empty
     * @param list<array{int, list<int>}> $blocks execution order plus wall samples per block, in block-index order
     */
    private function writeRun(RunSlot $slot, array $wallNs, string $sql, array $blocks = [], ?ResultSet $resultSet = null, ?QueryPlan $plan = null): RunDirectory
    {
        $database = new DatabaseTarget(engine: Engine::MySql, version: '8.0');
        $protocol = new Protocol(
            databases: [$database],
            tiers: [Tier::S],
            warmupIterations: 1,
            blockWarmupIterations: 2,
            measuredIterations: 3,
            blocks: 1,
            scenarioFilter: null,
        );

        $run = new RunDirectory(Path::fromString($this->workDir)->join($slot->value));
        $run->initialize(new RunManifest(
            runId: 'test-' . $slot->value,
            createdAt: new DateTimeImmutable('2026-08-13T12:00:00+00:00'),
            implementationReferenceType: ReferenceType::Release,
            implementationReference: 'v6.6.0.0',
            implementationIdentity: new Identity(id: 'fp-' . $slot->value, label: 'label ' . $slot->value),
            protocol: $protocol,
            environment: new ExecutionEnvironment(
                danRevision: str_repeat('d', 64),
                phpVersion: '8.4.24',
                composerVersion: '2.10.3',
                host: new HostMachine(operatingSystem: 'Linux 6.8.0-1021-azure', architecture: 'x86_64', cpuModel: 'AMD EPYC 7763 64-Core Processor', cpuLimit: null, memoryLimitBytes: null),
                dockerEngine: new DockerEngine(version: '29.5.2', operatingSystem: 'Ubuntu 24.04.4 LTS', architecture: 'x86_64', cpus: 4, memoryBytes: 16_775_622_656, userlandProxy: false),
                databaseImages: [new DatabaseImage(target: new DatabaseTarget(engine: Engine::MySql, version: '8.0'), digest: 'sha256:7dcddc01f13bab2f15cde676d44d01f61fc9f99fe7785e86196dfc07d358ae2b')],
                databaseNetworkPath: DatabaseNetworkPath::PublishedPort,
            ),
        ));
        if ($blocks === []) {
            $blocks = [
                [
                    $slot === RunSlot::Baseline ? 0 : 1,
                    $wallNs,
                ],
            ];
        }
        $blockResults = [];
        foreach (
            $blocks as $blockIndex => [
                $executionOrder,
                $blockWallNs,
            ]
        ) {
            $blockResults[] = new BlockResult(
                blockIndex: $blockIndex,
                executionOrder: $executionOrder,
                warmupIterations: 1,
                resultSet: $resultSet ?? new ResultSet(ids: [
                    'a',
                    'b',
                ], total: 2),
                resultSetConsistent: true,
                wallSamples: SampleCollection::fromArray($blockWallNs),
                statements: StatementProfileCollection::create([
                    new StatementProfile(index: 0, sql: $sql, durationSamples: SampleCollection::fromArray($blockWallNs), observed: count($blockWallNs), divergence: StatementDivergence::None, plan: $plan),
                ]),
            );
        }
        $run->writeCell(
            id: new CellId(scenario: ScenarioName::fromString('scenario.one'), tier: Tier::S, database: $database),
            result: new CellResult(
                scenario: ScenarioName::fromString('scenario.one'),
                tier: Tier::S,
                database: $database,
                blocks: BlockResultCollection::inExecutionOrder($blockResults),
            ),
        );

        return $run;
    }
}
