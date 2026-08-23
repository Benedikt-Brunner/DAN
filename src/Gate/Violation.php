<?php

declare(strict_types=1);

namespace Dan\Harness\Gate;

use Dan\Harness\Comparison\CellComparison;
use InvalidArgumentException;

/**
 * One gate violation: which cell, what kind, and (for regressions) the limit
 * that was breached. Wording is presentation and lives in the report
 * renderer.
 */
final class Violation
{
    public function __construct(
        public readonly ViolationKind $kind,
        public readonly CellComparison $cell,
        public readonly ?float $limitPct = null,
    ) {
        if ($kind === ViolationKind::WallRegression && $limitPct === null) {
            throw new InvalidArgumentException('A wall regression violation must carry the limit it breached.');
        }
    }
}
