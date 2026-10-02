<?php

declare(strict_types=1);

namespace Dan\Harness\Comparison;

use Dan\Harness\Measurement\Result\Statistics;
use Dan\Harness\RunStore\Artifact\BlockResult;
use Dan\Harness\RunStore\Artifact\BlockResultCollection;
use Dan\Harness\RunStore\Artifact\CellResult;
use Dan\Harness\RunStore\Artifact\StatementProfile;
use Dan\Harness\RunStore\Filesystem\RunDirectory;

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
            $cells[] = self::compareCell(
                baselineCell: $baseline->readCellByFileName($fileName),
                candidateCell: $candidate->readCellByFileName($fileName),
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

    private static function compareCell(CellResult $baselineCell, CellResult $candidateCell): CellComparison
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
        $baselineWallStatistics = Statistics::create($baselineCell->wallSamples());
        $candidateWallStatistics = Statistics::create($candidateCell->wallSamples());

        return new CellComparison(
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
                baselineMedianWall: Statistics::create($baselineBlock->wallSamples)->median(),
                candidateMedianWall: Statistics::create($candidateBlock->wallSamples)->median(),
            );
        }
        usort($pairs, fn (BlockComparison $a, BlockComparison $b): int => $a->blockIndex <=> $b->blockIndex);

        return $pairs;
    }
}
