<?php

declare(strict_types=1);

namespace Dan\Harness\Tests\Comparison;

use Dan\Harness\Comparison\RunComparator;
use Dan\Harness\Gate\Policy;
use Dan\Harness\Implementation\Identity\Identity;
use Dan\Harness\Implementation\Reference\ReferenceType;
use Dan\Harness\Measurement\Result\SampleCollection;
use Dan\Harness\Measurement\Scheduling\RunSlot;
use Dan\Harness\Protocol\DatabaseTarget;
use Dan\Harness\Protocol\Engine;
use Dan\Harness\Protocol\Protocol;
use Dan\Harness\RunStore\Artifact\CellId;
use Dan\Harness\RunStore\Artifact\CellResult;
use Dan\Harness\RunStore\Artifact\RunManifest;
use Dan\Harness\RunStore\Artifact\StatementProfile;
use Dan\Harness\RunStore\Artifact\StatementProfileCollection;
use Dan\Harness\RunStore\Filesystem\RunDirectory;
use Dan\Lib\Filesystem\Path;
use Dan\Lib\Protocol\ScenarioName;
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
        self::assertCount(1, $comparison->cells);
        // IN-list arity differences are data-shape noise, not SQL changes.
        self::assertFalse($comparison->cells[0]->sqlChanged);
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

        self::assertTrue($comparison->cells[0]->sqlChanged);
        self::assertSame([0], $comparison->cells[0]->changedStatementIndices);
        self::assertSame(100.0, $comparison->cells[0]->wallDeltaPct());

        $violations = (new Policy(maxWallRegressionPct: 15.0, failOnSqlChange: true))->evaluate($comparison->cells);
        self::assertCount(2, $violations);
    }

    /**
     * @param list<int> $wallNs integer nanoseconds
     */
    private function writeRun(RunSlot $slot, array $wallNs, string $sql): RunDirectory
    {
        $database = new DatabaseTarget(engine: Engine::MySql, version: '8.0');
        $protocol = new Protocol(
            databases: [$database],
            tiers: [Tier::S],
            warmupIterations: 1,
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
        ));
        $run->writeCell(
            id: new CellId(scenario: ScenarioName::fromString('scenario.one'), tier: Tier::S, database: $database),
            result: new CellResult(
                scenario: ScenarioName::fromString('scenario.one'),
                tier: Tier::S,
                database: $database,
                wallSamples: SampleCollection::fromArray($wallNs),
                statements: StatementProfileCollection::create([
                    new StatementProfile(index: 0, sql: $sql, durationSamples: SampleCollection::fromArray($wallNs), divergent: false),
                ]),
            ),
        );

        return $run;
    }
}
