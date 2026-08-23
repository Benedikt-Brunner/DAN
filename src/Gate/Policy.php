<?php

declare(strict_types=1);

namespace Dan\Harness\Gate;

use Dan\Harness\Comparison\CellComparison;

/**
 * CI gating. Latency gating only ever applies to within-job A/B comparisons
 * (both runs recorded in the same session on the same host) - cross-job
 * latency numbers from shared runners are not comparable and must not gate.
 */
final class Policy
{
    public function __construct(
        public readonly ?float $maxWallRegressionPct,
        public readonly bool $failOnSqlChange,
    ) {}

    /**
     * @param list<CellComparison> $cells
     *
     * @return list<Violation> empty means the gate passes
     */
    public function evaluate(array $cells): array
    {
        $violations = [];
        foreach ($cells as $cell) {
            if ($this->failOnSqlChange && $cell->sqlChanged) {
                $violations[] = new Violation(kind: ViolationKind::SqlChanged, cell: $cell);
            }
            if ($this->maxWallRegressionPct !== null && $cell->wallDeltaPct() > $this->maxWallRegressionPct) {
                $violations[] = new Violation(kind: ViolationKind::WallRegression, cell: $cell, limitPct: $this->maxWallRegressionPct);
            }
        }

        return $violations;
    }
}
