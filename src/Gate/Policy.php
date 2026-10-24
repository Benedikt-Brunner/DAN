<?php

declare(strict_types=1);

namespace Dan\Harness\Gate;

use Dan\Harness\Comparison\CellComparison;

/**
 * CI gating. Latency gating only ever applies to within-job A/B comparisons
 * (both runs recorded in the same session on the same host) - cross-job
 * latency numbers from shared runners are not comparable and must not gate.
 *
 * A wall regression is a decision over the two sample distributions, not
 * over two point estimates: the shift's confidence interval must exclude
 * zero (the difference is not resampling noise) AND the estimated shift must
 * exceed the limit (the difference matters).
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
            if ($this->failOnSqlChange && $cell->sqlChanged()) {
                $violations[] = new Violation(kind: ViolationKind::SqlChanged, cell: $cell);
            }
            if ($this->maxWallRegressionPct !== null && $cell->wallShift->isRegressionBeyond($this->maxWallRegressionPct)) {
                $violations[] = new Violation(kind: ViolationKind::WallRegression, cell: $cell, limitPct: $this->maxWallRegressionPct);
            }
        }

        return $violations;
    }
}
