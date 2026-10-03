<?php

declare(strict_types=1);

namespace Dan\Harness\Comparison;

use Dan\Harness\Measurement\Result\Statistics;
use Dan\Harness\RunStore\Artifact\BlockResultCollection;
use Dan\Harness\RunStore\Artifact\StatementProfile;
use Dan\Harness\RunStore\Filesystem\RunDirectory;
use Dan\Lib\Order\Sort;

final class RunComparator
{
    public static function compare(RunDirectory $baseline, RunDirectory $candidate): RunComparison
    {
        $baselineManifest = $baseline->manifest();
        $candidateManifest = $candidate->manifest();

        $baselineFiles = $baseline->cellFileNames();
        $candidateFiles = $candidate->cellFileNames();
        $sharedFiles = array_values(array_intersect($baselineFiles, $candidateFiles));

        $cells = [];
        foreach ($sharedFiles as $fileName) {
            $baselineCell = $baseline->readCellByFileName($fileName);
            $candidateCell = $candidate->readCellByFileName($fileName);

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
            $baselineWallStatistics = Statistics::create($baselineCell->wallSamples());
            $candidateWallStatistics = Statistics::create($candidateCell->wallSamples());

            $cells[] = new CellComparison(
                scenario: $baselineCell->scenario,
                tier: $baselineCell->tier,
                database: $baselineCell->database,
                baselineStatementCount: count($baselineStatements),
                candidateStatementCount: count($candidateStatements),
                sqlChanged: $changedIndices !== [],
                changedStatementIndices: $changedIndices,
                baselineMedianWall: $baselineWallStatistics->median(),
                candidateMedianWall: $candidateWallStatistics->median(),
                baselineP95Wall: $baselineWallStatistics->percentile(Statistics::P95),
                candidateP95Wall: $candidateWallStatistics->percentile(Statistics::P95),
                divergent: $divergent,
                blocks: self::compareBlocks(baseline: $baselineCell->blocks, candidate: $candidateCell->blocks),
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
                baselineMedianWall: Statistics::create($baselineBlock->wallSamples)->median(),
                candidateMedianWall: Statistics::create($candidateBlock->wallSamples)->median(),
            );
        }

        return BlockComparisonCollection::create(Sort::ascending($pairs));
    }
}
