<?php

declare(strict_types=1);

namespace Dan\Harness\Console\Diff;

use Dan\Lib\Filesystem\Path;

/**
 * Typed view of one `dan diff` invocation's CLI input. Produced by
 * InputParser; everything past the command boundary works with this object,
 * never with symfony's mixed-typed accessors.
 */
final class DiffOptions
{
    public function __construct(
        public readonly Path $baseline,
        public readonly Path $candidate,
        public readonly ?Path $outputFile,
        public readonly ?float $maxWallRegressionPct,
        public readonly bool $failOnSqlChange,
        public readonly bool $allowProtocolMismatch,
    ) {}
}
