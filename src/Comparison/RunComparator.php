<?php

declare(strict_types=1);

namespace Dan\Harness\Comparison;

use Dan\Harness\Measurement\Result\Statistics;
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
            $baselineCell = $baseline->readCellByFileName($fileName);
            $candidateCell = $candidate->readCellByFileName($fileName);

            $normalizedBaseline = array_map(fn (StatementProfile $statement) => SqlNormalizer::normalize($statement->sql), $baselineCell->statements->getItems());
            $normalizedCandidate = array_map(fn (StatementProfile $statement) => SqlNormalizer::normalize($statement->sql), $candidateCell->statements->getItems());

            $changedIndices = [];
            $max = max(count($normalizedBaseline), count($normalizedCandidate));
            for ($i = 0; $i < $max; ++$i) {
                if (($normalizedBaseline[$i] ?? null) !== ($normalizedCandidate[$i] ?? null)) {
                    $changedIndices[] = $i;
                }
            }

            $divergent = array_reduce(
                [
                    ...$baselineCell->statements->getItems(),
                    ...$candidateCell->statements->getItems(),
                ],
                fn (bool $carry, StatementProfile $statement) => $carry || $statement->divergent,
                false,
            );
            $baselineWallStatistics = Statistics::create($baselineCell->wallSamples);
            $candidateWallStatistics = Statistics::create($candidateCell->wallSamples);

            $cells[] = new CellComparison(
                scenario: $baselineCell->scenario,
                tier: $baselineCell->tier,
                database: $baselineCell->database,
                baselineStatementCount: count($baselineCell->statements),
                candidateStatementCount: count($candidateCell->statements),
                sqlChanged: $changedIndices !== [],
                changedStatementIndices: $changedIndices,
                baselineMedianWall: $baselineWallStatistics->median(),
                candidateMedianWall: $candidateWallStatistics->median(),
                baselineP95Wall: $baselineWallStatistics->percentile(Statistics::P95),
                candidateP95Wall: $candidateWallStatistics->percentile(Statistics::P95),
                divergent: $divergent,
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
}
