<?php

declare(strict_types=1);

namespace Dan\Harness\Tests\Comparison;

use Dan\Harness\Comparison\RunComparator;
use Dan\Harness\Measurement\Result\SampleCollection;
use Dan\Harness\RunStore\Artifact\CellId;
use Dan\Harness\RunStore\Artifact\CellResult;
use Dan\Harness\RunStore\Artifact\RunManifest;
use Dan\Harness\RunStore\Artifact\StatementProfile;
use Dan\Harness\RunStore\Artifact\StatementProfileCollection;
use Dan\Harness\RunStore\Filesystem\RunDirectory;
use Dan\Harness\Tests\DomainGenerators;
use Dan\Harness\Tests\PropertyTestCase;
use Dan\Lib\Filesystem\Path;
use Eris\Generator;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * The A/A property at the unit level, over real run directories: two runs
 * persisted from identical results must diff clean, whatever the results
 * are. Every false positive DAN could report in CI is a violation of this
 * property. The A/B properties pin the complement: a changed statement is
 * flagged at exactly its position, and cells present on one side only are
 * reported instead of silently dropped.
 */
final class RunComparatorPropertyTest extends PropertyTestCase
{
    public function testIdenticalRunsAlwaysDiffClean(): void
    {
        $this->forAll(
            DomainGenerators::runManifest(),
            $this->cellResults(),
        )->then(
            function (RunManifest $manifest, mixed $cells): void {
                $cells = DomainGenerators::asCellResults($cells);
                $this->inTwoRunDirectories(function (RunDirectory $baseline, RunDirectory $candidate) use ($manifest, $cells): void {
                    $written = $this->writeRun(directory: $baseline, manifest: $manifest, cells: $cells);
                    $this->writeRun(directory: $candidate, manifest: $manifest, cells: $cells);

                    $comparison = RunComparator::compare(baseline: $baseline, candidate: $candidate);

                    self::assertTrue($comparison->protocolsMatch);
                    self::assertSame([], $comparison->cellsOnlyInBaseline);
                    self::assertSame([], $comparison->cellsOnlyInCandidate);
                    self::assertCount(count($written), $comparison->cells);
                    foreach ($comparison->cells as $cell) {
                        $fileName = (new CellId(scenario: $cell->scenario, tier: $cell->tier, database: $cell->database))->fileName();

                        self::assertFalse($cell->sqlChanged);
                        self::assertSame([], $cell->changedStatementIndices);
                        self::assertSame(0.0, $cell->wallDeltaPct());
                        self::assertSame($cell->baselineStatementCount, $cell->candidateStatementCount);
                        self::assertSame($cell->baselineMedianWall->toNsFloat(), $cell->candidateMedianWall->toNsFloat());
                        self::assertSame($cell->baselineP95Wall->toNsFloat(), $cell->candidateP95Wall->toNsFloat());
                        // Divergence recorded within a run must surface, and
                        // a clean run must never invent one.
                        self::assertSame($this->anyStatementDivergent($written[$fileName]), $cell->divergent, $fileName);
                    }
                    self::assertCount(count($written), $baseline->allCells());
                });
            },
        );
    }

    public function testChangedSqlIsFlaggedAtExactlyTheChangedPositions(): void
    {
        $this->forAll(
            DomainGenerators::runManifest(),
            $this->cellWithChangedPositions(),
            Generator\bool(),
        )->then(
            function (RunManifest $manifest, mixed $pair, bool $candidateCarriesDivergence): void {
                $parts = DomainGenerators::asList($pair);
                $cell = DomainGenerators::asCellResult($parts[0]);
                $changedPositions = DomainGenerators::asIntList($parts[1]);
                $this->inTwoRunDirectories(function (RunDirectory $baseline, RunDirectory $candidate) use ($manifest, $cell, $changedPositions, $candidateCarriesDivergence): void {
                    // Divergence flags land on exactly one side, so a
                    // comparator that ignores either side's statements is
                    // caught in both directions.
                    $rewritten = $this->withRewrittenSql(cell: $cell, positions: $changedPositions, divergent: $candidateCarriesDivergence);
                    $this->writeRun(directory: $baseline, manifest: $manifest, cells: [$this->withRewrittenSql(cell: $cell, positions: [], divergent: !$candidateCarriesDivergence)]);
                    $this->writeRun(directory: $candidate, manifest: $manifest, cells: [$rewritten]);

                    $comparison = RunComparator::compare(baseline: $baseline, candidate: $candidate);

                    self::assertCount(1, $comparison->cells);
                    self::assertSame($changedPositions !== [], $comparison->cells[0]->sqlChanged);
                    self::assertSame($changedPositions, $comparison->cells[0]->changedStatementIndices);
                    self::assertTrue($comparison->cells[0]->divergent, 'A divergence flag on either side must surface.');
                });
            },
        );
    }

    public function testCellsPresentOnOneSideOnlyAreReportedInsteadOfCompared(): void
    {
        $this->forAll(
            DomainGenerators::runManifest(),
            $this->cellResults(),
        )->then(
            function (RunManifest $manifest, mixed $cells): void {
                $cells = DomainGenerators::asCellResults($cells);
                $this->inTwoRunDirectories(function (RunDirectory $baseline, RunDirectory $candidate) use ($manifest, $cells): void {
                    // Baseline misses the first cell, candidate misses the
                    // last: both "only present in" directions and the shared
                    // intersection are exercised at once.
                    $all = $this->writeRun(directory: $baseline, manifest: $manifest, cells: $cells);
                    $fileNames = array_keys($all);
                    $first = $fileNames[0];
                    $last = $fileNames[count($fileNames) - 1];
                    $this->removeDirectory($baseline->root->toString());
                    $this->writeRun(directory: $baseline, manifest: $manifest, cells: array_values(array_diff_key($all, [$first => true])));
                    $this->writeRun(directory: $candidate, manifest: $manifest, cells: array_values(array_diff_key($all, [$last => true])));

                    $comparison = RunComparator::compare(baseline: $baseline, candidate: $candidate);

                    self::assertSame($first === $last ? [] : [$last], $comparison->cellsOnlyInBaseline);
                    self::assertSame($first === $last ? [] : [$first], $comparison->cellsOnlyInCandidate);
                    self::assertCount(max(0, count($all) - 2), $comparison->cells);
                });
            },
        );
    }

    public function testRunDirectoryRoundTripsManifestAndCellsAndMergesBlocks(): void
    {
        $this->forAll(
            DomainGenerators::runManifest(),
            DomainGenerators::cellResult(),
            DomainGenerators::samples(),
        )->then(
            function (RunManifest $manifest, CellResult $cell, mixed $secondBlockWallSamples): void {
                $secondBlockWallSamples = DomainGenerators::asIntList($secondBlockWallSamples);
                $this->inRunDirectory(function (RunDirectory $directory) use ($manifest, $cell, $secondBlockWallSamples): void {
                    $directory->initialize($manifest);
                    $id = new CellId(scenario: $cell->scenario, tier: $cell->tier, database: $cell->database);
                    $secondBlock = new CellResult(
                        scenario: $cell->scenario,
                        tier: $cell->tier,
                        database: $cell->database,
                        wallSamples: SampleCollection::fromArray($secondBlockWallSamples),
                        statements: $cell->statements,
                    );

                    $directory->mergeIntoCell(id: $id, result: $cell);
                    $directory->mergeIntoCell(id: $id, result: $secondBlock);

                    self::assertSame($manifest->toArray(), $directory->manifest()->toArray());
                    self::assertSame([$id->fileName()], $directory->cellFileNames());
                    self::assertSame(
                        $cell->merge($secondBlock)->toArray(),
                        $directory->readCellByFileName($id->fileName())->toArray(),
                        'Two merged block results must equal the in-memory merge of both.',
                    );
                    self::assertCount(1, $directory->allCells());
                });
            },
        );
    }

    /** @return Generator<mixed> */
    private function cellResults(): Generator
    {
        return DomainGenerators::boundedList(elements: DomainGenerators::cellResult(), maxLength: 4);
    }

    /**
     * A cell plus a subset of its statement positions to rewrite - including
     * the empty subset, so the property also proves the absence of false
     * positives.
     */
    /** @return Generator<mixed> */
    private function cellWithChangedPositions(): Generator
    {
        return Generator\bind(
            DomainGenerators::cellResult(),
            fn (CellResult $cell): Generator => Generator\map(
                /** @param list<int> $positions */
                fn (array $positions): array => [
                    $cell,
                    array_values($positions),
                ],
                Generator\subset(range(0, count($cell->statements) - 1)),
            ),
        );
    }

    /**
     * @param list<int> $positions
     */
    private function withRewrittenSql(CellResult $cell, array $positions, bool $divergent): CellResult
    {
        $statements = [];
        foreach ($cell->statements as $index => $statement) {
            $statements[] = new StatementProfile(
                index: $statement->index,
                sql: in_array($index, $positions, true) ? $statement->sql . ' AND rewritten = 1' : $statement->sql,
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

    private function anyStatementDivergent(CellResult $cell): bool
    {
        foreach ($cell->statements as $statement) {
            if ($statement->divergent) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param callable(RunDirectory, RunDirectory): void $assertion
     */
    private function inTwoRunDirectories(callable $assertion): void
    {
        $this->inScratchDirectory(function (string $base) use ($assertion): void {
            $assertion(
                new RunDirectory(Path::fromString($base . '/baseline')),
                new RunDirectory(Path::fromString($base . '/candidate')),
            );
        });
    }

    /**
     * @param callable(RunDirectory): void $assertion
     */
    private function inRunDirectory(callable $assertion): void
    {
        $this->inScratchDirectory(function (string $base) use ($assertion): void {
            $assertion(new RunDirectory(Path::fromString($base . '/run')));
        });
    }

    /**
     * Runs the assertion against a fresh scratch root and removes it
     * afterwards, keeping property iterations independent.
     *
     * @param callable(string): void $assertion
     */
    private function inScratchDirectory(callable $assertion): void
    {
        $base = sys_get_temp_dir() . '/dan-comparator-property-' . bin2hex(random_bytes(6));

        try {
            $assertion($base);
        } finally {
            $this->removeDirectory($base);
        }
    }

    /**
     * Writes a manifest plus every cell, deduplicated by cell file name the
     * same way real runs are keyed on disk.
     *
     * @param list<CellResult> $cells
     *
     * @return array<string, CellResult> written cells keyed by file name
     */
    private function writeRun(RunDirectory $directory, RunManifest $manifest, array $cells): array
    {
        $directory->initialize($manifest);
        $written = [];
        foreach ($cells as $cell) {
            $id = new CellId(scenario: $cell->scenario, tier: $cell->tier, database: $cell->database);
            $directory->writeCell(id: $id, result: $cell);
            $written[$id->fileName()] = $cell;
        }
        ksort($written);

        return $written;
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            if (!$file instanceof SplFileInfo) {
                continue;
            }
            $removed = $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            if (!$removed) {
                throw new RuntimeException(sprintf('Could not remove "%s".', $file->getPathname()));
            }
        }
        if (!rmdir($directory)) {
            throw new RuntimeException(sprintf('Could not remove "%s".', $directory));
        }
    }
}
