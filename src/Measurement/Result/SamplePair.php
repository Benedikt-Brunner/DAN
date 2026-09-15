<?php

declare(strict_types=1);

namespace Dan\Harness\Measurement\Result;

use InvalidArgumentException;

/**
 * Baseline and candidate samples that belong together - one mirrored block
 * pair, or the pooled samples of a cell. The unit a shift estimate resamples
 * within, so block structure survives the resampling.
 */
final class SamplePair
{
    public function __construct(
        public readonly SampleCollection $baseline,
        public readonly SampleCollection $candidate,
    ) {
        if ($baseline->empty() || $candidate->empty()) {
            throw new InvalidArgumentException('A sample pair needs at least one sample on each side.');
        }
    }
}
