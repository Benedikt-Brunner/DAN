<?php

declare(strict_types=1);

namespace Dan\Harness\Comparison;

use Dan\Harness\Measurement\Result\MedianShiftEstimator;
use Dan\Harness\Measurement\Result\SampleCollection;
use Dan\Harness\Measurement\Result\SamplePair;
use Dan\Harness\Measurement\Result\Statistics;
use Dan\Harness\Measurement\Scheduling\RunSlot;
use Dan\Harness\Protocol\Engine;
use Dan\Harness\RunStore\Artifact\BlockResult;
use Dan\Harness\RunStore\Artifact\BlockResultCollection;
use Dan\Harness\RunStore\Artifact\CellResult;
use Dan\Harness\RunStore\Artifact\StatementProfile;
use Dan\Harness\RunStore\Artifact\StatementProfileCollection;
use Dan\Harness\RunStore\Filesystem\RunDirectory;

final class RunComparator
{
    /**
     * The estimator is injectable so tests can trade resamples for speed;
     * production runs use its defaults.
     */
    public static function compare(RunDirectory $baseline, RunDirectory $candidate, MedianShiftEstimator $shiftEstimator = new MedianShiftEstimator()): RunComparison
    {
        $baselineManifest = $baseline->manifest();
        $candidateManifest = $candidate->manifest();

        $baselineFiles = $baseline->cellFileNames();
        $candidateFiles = $candidate->cellFileNames();
        $sharedFiles = array_values(array_intersect($baselineFiles, $candidateFiles));

        $cells = [];
        foreach ($sharedFiles as $fileName) {
            $cells[] = self::compareCell(
                baselineCell: $baseline->readCellByFileName($fileName),
                candidateCell: $candidate->readCellByFileName($fileName),
                shiftEstimator: $shiftEstimator,
            );
        }

        return new RunComparison(
            baselineManifest: $baselineManifest,
            candidateManifest: $candidateManifest,
            protocolsMatch: $baselineManifest->protocol->equals($candidateManifest->protocol),
            environmentsComparable: $baselineManifest->environment->comparableTo($candidateManifest->environment),
            datasetDivergences: self::datasetDivergences(baseline: $baseline, candidate: $candidate),
            cells: $cells,
            cellsOnlyInBaseline: array_values(array_diff($baselineFiles, $candidateFiles)),
            cellsOnlyInCandidate: array_values(array_diff($candidateFiles, $baselineFiles)),
        );
    }

    /**
     * The seeded datasets both runs recorded fingerprints for, wherever the
     * fingerprints disagree. A dataset fingerprinted on one side only is not
     * a divergence - there is nothing to compare it with.
     *
     * @return list<DatasetDivergence>
     */
    private static function datasetDivergences(RunDirectory $baseline, RunDirectory $candidate): array
    {
        $divergences = [];
        foreach (array_intersect($baseline->datasetFileNames(), $candidate->datasetFileNames()) as $fileName) {
            $baselineDataset = $baseline->readDatasetByFileName($fileName);
            $differences = $baselineDataset->fingerprint->differences($candidate->readDatasetByFileName($fileName)->fingerprint);
            if ($differences === []) {
                continue;
            }
            $divergences[] = new DatasetDivergence(
                tier: $baselineDataset->tier,
                database: $baselineDataset->database,
                differences: $differences,
            );
        }

        return $divergences;
    }

    private static function compareCell(CellResult $baselineCell, CellResult $candidateCell, MedianShiftEstimator $shiftEstimator): CellComparison
    {
        $baselineStatements = $baselineCell->statements();
        $candidateStatements = $candidateCell->statements();
        $alignment = StatementAligner::align(
            baseline: array_map(fn (StatementProfile $statement) => SqlNormalizer::normalize($statement->sql), $baselineStatements->getItems()),
            candidate: array_map(fn (StatementProfile $statement) => SqlNormalizer::normalize($statement->sql), $candidateStatements->getItems()),
        );

        $baselineWall = $baselineCell->wallSamples();
        $candidateWall = $candidateCell->wallSamples();
        $unstableStatements = [
            ...self::unstableStatements(slot: RunSlot::Baseline, statements: $baselineStatements, iterations: count($baselineWall)),
            ...self::unstableStatements(slot: RunSlot::Candidate, statements: $candidateStatements, iterations: count($candidateWall)),
        ];
        $baselineWallStatistics = Statistics::create($baselineWall);
        $candidateWallStatistics = Statistics::create($candidateWall);
        $blocks = self::compareBlocks(baseline: $baselineCell->blocks, candidate: $candidateCell->blocks);

        return new CellComparison(
            scenario: $baselineCell->scenario,
            tier: $baselineCell->tier,
            database: $baselineCell->database,
            baselineStatementCount: count($baselineStatements),
            candidateStatementCount: count($candidateStatements),
            resultSets: new ResultSetComparison(
                baseline: $baselineCell->resultSet(),
                candidate: $candidateCell->resultSet(),
                baselineConsistent: $baselineCell->resultSetConsistent(),
                candidateConsistent: $candidateCell->resultSetConsistent(),
            ),
            alignment: $alignment,
            baselineSampleCount: count($baselineWall),
            candidateSampleCount: count($candidateWall),
            baselineMedianWall: $baselineWallStatistics->median(),
            candidateMedianWall: $candidateWallStatistics->median(),
            baselineP95Wall: $baselineWallStatistics->percentile(Statistics::P95),
            candidateP95Wall: $candidateWallStatistics->percentile(Statistics::P95),
            wallShift: $shiftEstimator->estimate(self::samplePairs(blocks: $blocks, baseline: $baselineWall, candidate: $candidateWall)),
            unstableStatements: $unstableStatements,
            planChanges: self::planChanges(
                alignment: $alignment,
                engine: $baselineCell->database->engine,
                baseline: $baselineStatements,
                candidate: $candidateStatements,
            ),
            blocks: $blocks,
        );
    }

    /**
     * One plan comparison per aligned change, so a reviewer can see why the
     * changed SQL ran differently - not for unchanged statements, whose plans
     * are still in the artifacts.
     *
     * @return list<StatementPlanComparison>
     */
    private static function planChanges(StatementAlignment $alignment, Engine $engine, StatementProfileCollection $baseline, StatementProfileCollection $candidate): array
    {
        $comparisons = [];
        foreach ($alignment->changes() as $change) {
            $comparisons[] = new StatementPlanComparison(
                statement: $change,
                engine: $engine,
                baseline: $change->baselineIndex === null ? null : $baseline[$change->baselineIndex]->plan,
                candidate: $change->candidateIndex === null ? null : $candidate[$change->candidateIndex]->plan,
            );
        }

        return $comparisons;
    }

    /**
     * @return list<StatementInstability>
     */
    private static function unstableStatements(RunSlot $slot, StatementProfileCollection $statements, int $iterations): array
    {
        $unstable = [];
        foreach ($statements as $statement) {
            if (!$statement->divergence->isDivergent()) {
                continue;
            }
            $unstable[] = new StatementInstability(
                slot: $slot,
                index: $statement->index,
                divergence: $statement->divergence,
                observed: $statement->observed,
                iterations: $iterations,
            );
        }

        return $unstable;
    }

    /**
     * The units the shift estimate resamples within: the paired blocks when
     * both runs recorded them, otherwise (an interrupted run) the pooled
     * samples as one pair.
     *
     * @param list<BlockComparison> $blocks
     *
     * @return list<SamplePair>
     */
    private static function samplePairs(array $blocks, SampleCollection $baseline, SampleCollection $candidate): array
    {
        if ($blocks === []) {
            return [new SamplePair(baseline: $baseline, candidate: $candidate)];
        }

        return array_map(
            fn (BlockComparison $block): SamplePair => new SamplePair(baseline: $block->baselineSamples, candidate: $block->candidateSamples),
            $blocks,
        );
    }

    /**
     * Pairs the two runs' blocks by block index. A block index present on one
     * side only (an interrupted run) has no pair and is left out - the pooled
     * numbers still cover its samples.
     *
     * @return list<BlockComparison>
     */
    private static function compareBlocks(BlockResultCollection $baseline, BlockResultCollection $candidate): array
    {
        $candidateByIndex = [];
        foreach ($candidate as $block) {
            $candidateByIndex[$block->blockIndex] = $block;
        }

        $pairs = [];
        foreach ($baseline as $baselineBlock) {
            $candidateBlock = $candidateByIndex[$baselineBlock->blockIndex] ?? null;
            if (!$candidateBlock instanceof BlockResult || $baselineBlock->wallSamples->empty() || $candidateBlock->wallSamples->empty()) {
                continue;
            }
            $pairs[] = new BlockComparison(
                blockIndex: $baselineBlock->blockIndex,
                baselineExecutionOrder: $baselineBlock->executionOrder,
                candidateExecutionOrder: $candidateBlock->executionOrder,
                baselineSamples: $baselineBlock->wallSamples,
                candidateSamples: $candidateBlock->wallSamples,
            );
        }
        usort($pairs, fn (BlockComparison $a, BlockComparison $b): int => $a->blockIndex <=> $b->blockIndex);

        return $pairs;
    }
}
