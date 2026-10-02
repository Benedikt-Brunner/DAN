<?php

declare(strict_types=1);

namespace Dan\Harness\Measurement\Result;

use InvalidArgumentException;

/**
 * The relative shift of the candidate's median wall time against the
 * baseline's, with its uncertainty: a confidence interval from resampling
 * the two distributions. Significance ("does the interval exclude zero?")
 * and magnitude ("is the estimate beyond the threshold?") are separate
 * questions, and a gate decision needs both answered.
 */
final class MedianShift
{
    public function __construct(
        public readonly float $estimatePct,
        public readonly float $lowerPct,
        public readonly float $upperPct,
        public readonly float $confidence,
        public readonly int $resamples,
    ) {
        if ($lowerPct > $upperPct) {
            throw new InvalidArgumentException(sprintf('A shift interval cannot run from %.3f%% down to %.3f%%.', $lowerPct, $upperPct));
        }
        if ($confidence <= 0.0 || $confidence >= 1.0) {
            throw new InvalidArgumentException(sprintf('The confidence level must lie strictly between 0 and 1, got %.3f.', $confidence));
        }
    }

    /**
     * True when the whole interval lies on one side of zero: the two
     * distributions differ beyond what resampling noise explains.
     */
    public function excludesZero(): bool
    {
        return $this->lowerPct > 0.0 || $this->upperPct < 0.0;
    }

    /**
     * The gate question: a regression that is both significant (the interval
     * excludes zero) and material (the estimate exceeds the limit). A large
     * but uncertain estimate is noise; a certain but tiny one is not worth
     * failing a build over.
     */
    public function isRegressionBeyond(float $limitPct): bool
    {
        return $this->excludesZero() && $this->estimatePct > $limitPct;
    }
}
