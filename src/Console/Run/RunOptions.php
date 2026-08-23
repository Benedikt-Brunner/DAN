<?php

declare(strict_types=1);

namespace Dan\Harness\Console\Run;

use Dan\Lib\Filesystem\AbsolutePath;

/**
 * Typed view of one `dan run` invocation's CLI input. Produced by
 * InputParser; everything past the command boundary works with this object,
 * never with symfony's mixed-typed accessors.
 */
final class RunOptions
{
    /**
     * @param list<string> $dalSpecs checkout paths or released versions, in comparison order (first = baseline)
     * @param list<string> $databaseSpecs e.g. ["mysql:8.0", "mariadb:11.4"]
     * @param list<string> $tiers e.g. ["S", "M"]
     */
    public function __construct(
        public readonly array $dalSpecs,
        public readonly array $databaseSpecs,
        public readonly array $tiers,
        public readonly int $warmupIterations,
        public readonly int $measuredIterations,
        public readonly int $blocks,
        public readonly ?string $scenarioFilter,
        public readonly AbsolutePath $outputDirectory,
        public readonly float $maxWallRegressionPct,
        public readonly bool $failOnSqlChange,
    ) {}
}
