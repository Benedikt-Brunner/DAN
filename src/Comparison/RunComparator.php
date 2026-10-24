<?php

declare(strict_types=1);

namespace Dan\Harness\Comparison;

use Dan\Harness\Measurement\Result\LatencyDelta;
use Dan\Harness\Measurement\Result\MedianShift;
use Dan\Harness\Measurement\Result\MedianShiftEstimator;
use Dan\Harness\Measurement\Result\SampleCollection;
use Dan\Harness\Measurement\Result\SamplePair;
use Dan\Harness\Measurement\Result\Statistics;
use Dan\Harness\Measurement\Scheduling\RunSlot;
use Dan\Harness\RunStore\Artifact\BlockResultCollection;
use Dan\Harness\RunStore\Artifact\CellResult;
use Dan\Harness\RunStore\Artifact\StatementProfile;
use Dan\Harness\RunStore\Artifact\StatementProfileCollection;
use Dan\Harness\RunStore\Filesystem\RunDirectory;
use Dan\Lib\Order\Sort;

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
            cells: $cells,
            cellsOnlyInBaseline: array_values(array_diff($baselineFiles, $candidateFiles)),
            cellsOnlyInCandidate: array_values(array_diff($candidateFiles, $baselineFiles)),
        );
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
            wallShift: self::wallShift(
                blocks: $blocks,
                baseline: $baselineWall,
                candidate: $candidateWall,
                shiftEstimator: $shiftEstimator,
            ),
            unstableStatements: $unstableStatements,
            blocks: $blocks,
        );
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
     * The shift estimate resamples within mirrored block pairs, so its
     * interval reflects the session's block design. That needs the pairs to
     * cover every recorded sample: an interrupted run leaves blocks without a
     * partner, and neither the matched blocks alone (different samples than
     * the reported medians) nor the pool (iterations treated as independent
     * draws, understating drift) yields an honest interval. Such a cell keeps
     * the pooled point estimate - the shift between the reported medians -
     * without an interval, so it cannot gate.
     */
    private static function wallShift(
        BlockComparisonCollection $blocks,
        SampleCollection $baseline,
        SampleCollection $candidate,
        MedianShiftEstimator $shiftEstimator,
    ): MedianShift {
        $pairs = array_map(
            fn (BlockComparison $block): SamplePair => new SamplePair(baseline: $block->baselineSamples, candidate: $block->candidateSamples),
            $blocks->getItems(),
        );
        $pairedBaseline = array_sum(array_map(fn (SamplePair $pair): int => count($pair->baseline), $pairs));
        $pairedCandidate = array_sum(array_map(fn (SamplePair $pair): int => count($pair->candidate), $pairs));
        if ($pairs === [] || $pairedBaseline !== count($baseline) || $pairedCandidate !== count($candidate)) {
            return MedianShift::withoutInterval(
                estimatePct: LatencyDelta::percent(baseline: Statistics::create($baseline)->median(), candidate: Statistics::create($candidate)->median()),
                confidence: MedianShiftEstimator::CONFIDENCE,
            );
        }

        return $shiftEstimator->estimate($pairs);
    }

    /**
     * Pairs the two runs' blocks by block index. A block index present on one
     * side only (an interrupted run) has no pair and is left out - the pooled
     * numbers still cover its samples.
     */
    private static function compareBlocks(BlockResultCollection $baseline, BlockResultCollection $candidate): BlockComparisonCollection
    {
        $pairs = [];
        foreach ($baseline as $baselineBlock) {
            $candidateBlock = $candidate->findByBlockIndex($baselineBlock->blockIndex);
            if ($candidateBlock === null || $baselineBlock->wallSamples->empty() || $candidateBlock->wallSamples->empty()) {
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

        return BlockComparisonCollection::create(Sort::ascending($pairs));
    }
}
