<?php

declare(strict_types=1);

namespace Dan\Harness\Comparison;

use Dan\Harness\RunStore\Artifact\RunManifest;

final class RunComparison
{
    /**
     * @param list<CellComparison> $cells
     * @param list<string> $cellsOnlyInBaseline cell file names present only in the baseline run
     * @param list<string> $cellsOnlyInCandidate
     */
    public function __construct(
        public readonly RunManifest $baselineManifest,
        public readonly RunManifest $candidateManifest,
        public readonly bool $protocolsMatch,
        public readonly array $cells,
        public readonly array $cellsOnlyInBaseline,
        public readonly array $cellsOnlyInCandidate,
    ) {}
}
