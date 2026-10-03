<?php

declare(strict_types=1);

namespace Dan\Harness\Measurement\Result;

use InvalidArgumentException;

/**
 * Baseline and candidate samples that belong together - one mirrored block
 * pair, or the pooled samples of a cell. The unit a shift estimate resamples
 * within, so block structure survives the resampling.
 *
 * Every sample must be a positive duration: a relative shift against a zero
 * baseline is undefined, and a timed iteration never takes zero nanoseconds,
 * so a zero sample is a corrupt measurement to refuse - not a delta of 0%.
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
        foreach (
            [
                ...$baseline->toNsArray(),
                ...$candidate->toNsArray(),
            ] as $ns
        ) {
            if ($ns <= 0) {
                throw new InvalidArgumentException('A sample pair needs positive wall times; a zero duration has no relative shift.');
            }
        }
    }
}
