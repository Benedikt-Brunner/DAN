<?php

declare(strict_types=1);

namespace Dan\Harness\Comparison;

use Dan\Harness\Protocol\DatabaseTarget;
use Dan\Lib\Protocol\Tier;

/**
 * Two runs whose seeded datasets for one (tier x database) are not logically
 * equal - every latency and SQL comparison over that dataset compares
 * different work. Carries the aspect-level differences so the report can
 * say which entity or mapping differs.
 */
final class DatasetDivergence
{
    /**
     * @param list<string> $differences aspect-level diagnostics from DatasetFingerprint::differences()
     */
    public function __construct(
        public readonly Tier $tier,
        public readonly DatabaseTarget $database,
        public readonly array $differences,
    ) {}
}
