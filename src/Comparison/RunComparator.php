<?php

declare(strict_types=1);

namespace Dan\Harness\Comparison;

use Dan\Harness\Measurement\Result\MedianShiftEstimator;
use Dan\Harness\Measurement\Result\SampleCollection;
use Dan\Harness\Measurement\Result\SamplePair;
use Dan\Harness\Measurement\Result\Statistics;
use Dan\Harness\RunStore\Artifact\BlockResultCollection;
use Dan\Harness\RunStore\Artifact\CellResult;
use Dan\Harness\RunStore\Artifact\StatementProfile;
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
            cells: $cells,
            cellsOnlyInBaseline: array_values(array_diff($baselineFiles, $candidateFiles)),
            cellsOnlyInCandidate: array_values(array_diff($candidateFiles, $baselineFiles)),
        );
    }

    private static function compareCell(CellResult $baselineCell, CellResult $candidateCell, MedianShiftEstimator $shiftEstimator): CellComparison
    {
        $baselineStatements = $baselineCell->statements();
        $candidateStatements = $candidateCell->statements();
        $normalizedBaseline = array_map(fn (StatementProfile $statement) => SqlNormalizer::normalize($statement->sql), $baselineStatements->getItems());
        $normalizedCandidate = array_map(fn (StatementProfile $statement) => SqlNormalizer::normalize($statement->sql), $candidateStatements->getItems());

        $changedIndices = [];
        $max = max(count($normalizedBaseline), count($normalizedCandidate));
        for ($i = 0; $i < $max; ++$i) {
            if (($normalizedBaseline[$i] ?? null) !== ($normalizedCandidate[$i] ?? null)) {
                $changedIndices[] = $i;
            }
        }

        $divergent = array_reduce(
            [
                ...$baselineStatements->getItems(),
                ...$candidateStatements->getItems(),
            ],
            fn (bool $carry, StatementProfile $statement) => $carry || $statement->divergent,
            false,
        );
        $baselineWall = $baselineCell->wallSamples();
        $candidateWall = $candidateCell->wallSamples();
        $baselineWallStatistics = Statistics::create($baselineWall);
        $candidateWallStatistics = Statistics::create($candidateWall);
        $blocks = self::compareBlocks(baseline: $baselineCell->blocks, candidate: $candidateCell->blocks);

        return new CellComparison(
            scenario: $baselineCell->scenario,
            tier: $baselineCell->tier,
            database: $baselineCell->database,
            baselineStatementCount: count($baselineStatements),
            candidateStatementCount: count($candidateStatements),
            sqlChanged: $changedIndices !== [],
            changedStatementIndices: $changedIndices,
            baselineSampleCount: count($baselineWall),
            candidateSampleCount: count($candidateWall),
            baselineMedianWall: $baselineWallStatistics->median(),
            candidateMedianWall: $candidateWallStatistics->median(),
            baselineP95Wall: $baselineWallStatistics->percentile(Statistics::P95),
            candidateP95Wall: $candidateWallStatistics->percentile(Statistics::P95),
            wallShift: $shiftEstimator->estimate(self::samplePairs(blocks: $blocks, baseline: $baselineWall, candidate: $candidateWall)),
            divergent: $divergent,
            blocks: $blocks,
        );
    }

    /**
     * The units the shift estimate resamples within: the paired blocks when
     * they cover every recorded sample, otherwise the pooled samples as one
     * pair. An interrupted run leaves blocks without a partner; estimating
     * from the matched blocks alone would gate on different samples than the
     * medians the report shows, so such a cell falls back to the pool.
     *
     * @return list<SamplePair>
     */
    private static function samplePairs(BlockComparisonCollection $blocks, SampleCollection $baseline, SampleCollection $candidate): array
    {
        $pairs = array_map(
            fn (BlockComparison $block): SamplePair => new SamplePair(baseline: $block->baselineSamples, candidate: $block->candidateSamples),
            $blocks->getItems(),
        );
        $pairedBaseline = array_sum(array_map(fn (SamplePair $pair): int => count($pair->baseline), $pairs));
        $pairedCandidate = array_sum(array_map(fn (SamplePair $pair): int => count($pair->candidate), $pairs));
        if ($pairs === [] || $pairedBaseline !== count($baseline) || $pairedCandidate !== count($candidate)) {
            return [new SamplePair(baseline: $baseline, candidate: $candidate)];
        }

        return $pairs;
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
