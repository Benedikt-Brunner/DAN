<?php

declare(strict_types=1);

namespace Dan\Harness\Measurement\Result;

use InvalidArgumentException;

/**
 * Baseline and candidate samples that belong together - one mirrored block
 * pair, or the pooled samples of a cell. The unit a shift estimate resamples
 * within, so block structure survives the resampling.
 *
 * Every baseline sample must be a positive duration: a relative shift
 * against a zero baseline is undefined, so such a sample is refused rather
 * than read as a delta of 0%. The candidate side has no such constraint.
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
        foreach ($baseline->toNsArray() as $ns) {
            if ($ns <= 0) {
                throw new InvalidArgumentException('A sample pair needs positive baseline wall times; a shift relative to zero is undefined.');
            }
        }
    }
}
