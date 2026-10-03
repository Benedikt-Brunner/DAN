<?php

declare(strict_types=1);

namespace Dan\Harness\Comparison;

use Dan\Harness\Protocol\DatabaseTarget;
use Dan\Lib\Protocol\ScenarioName;
use Dan\Lib\Protocol\Tier;
use Dan\Lib\Time\Duration;

final class CellComparison
{
    /**
     * @param list<int> $changedStatementIndices
     */
    public function __construct(
        public readonly ScenarioName $scenario,
        public readonly Tier $tier,
        public readonly DatabaseTarget $database,
        public readonly int $baselineStatementCount,
        public readonly int $candidateStatementCount,
        public readonly bool $sqlChanged,
        public readonly array $changedStatementIndices,
        public readonly Duration $baselineMedianWall,
        public readonly Duration $candidateMedianWall,
        public readonly Duration $baselineP95Wall,
        public readonly Duration $candidateP95Wall,
        public readonly bool $divergent,
        public readonly BlockComparisonCollection $blocks,
    ) {}

    public function wallDeltaPct(): float
    {
        return LatencyDelta::percent(baseline: $this->baselineMedianWall, candidate: $this->candidateMedianWall);
    }
}
