<?php

declare(strict_types=1);

namespace Dan\Harness\Measurement\Result;

use Dan\Lib\Time\Duration;

/**
 * Relative change of a candidate latency against its baseline, in percent.
 * A zero baseline has no meaningful relative change and reports zero rather
 * than dividing by it.
 */
final class LatencyDelta
{
    public static function percent(Duration $baseline, Duration $candidate): float
    {
        $baselineNs = $baseline->toNsFloat();
        if ($baselineNs <= 0.0) {
            return 0.0;
        }

        return (($candidate->toNsFloat() - $baselineNs) / $baselineNs) * 100;
    }
}
