<?php

declare(strict_types=1);

namespace Dan\Harness\Measurement\Result;

use InvalidArgumentException;
use MathPHP\Statistics\Descriptive;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Estimates the median shift between baseline and candidate from their full
 * sample distributions with a percentile bootstrap. Resampling is stratified
 * by pair: every measurement block pair is resampled within itself and the
 * resampled blocks are pooled, so the estimate's uncertainty reflects the
 * session's block design instead of pretending all iterations were
 * independent draws.
 *
 * The resampling is seeded, so the same two runs always yield the same
 * interval - a report must be reproducible from its artifacts. Percentiles
 * of the bootstrap distribution come from MathPHP, like every other
 * statistic in DAN.
 */
final class MedianShiftEstimator
{
    public const int DEFAULT_RESAMPLES = 1000;
    public const float CONFIDENCE = 0.95;

    /**
     * Fixed on purpose: a report is a function of the artifacts, not of the
     * moment it was rendered.
     */
    private const int SEED = 20260915;

    public function __construct(
        private readonly int $resamples = self::DEFAULT_RESAMPLES,
    ) {
        if ($resamples < 2) {
            throw new InvalidArgumentException('A bootstrap needs at least two resamples to form an interval.');
        }
    }

    /**
     * @param list<SamplePair> $pairs
     */
    public function estimate(array $pairs): MedianShift
    {
        if ($pairs === []) {
            throw new InvalidArgumentException('A median shift needs at least one sample pair.');
        }

        $estimate = self::shiftPct(
            baseline: self::pool(array_map(fn (SamplePair $pair): SampleCollection => $pair->baseline, $pairs)),
            candidate: self::pool(array_map(fn (SamplePair $pair): SampleCollection => $pair->candidate, $pairs)),
        );

        $randomizer = new Randomizer(new Mt19937(self::SEED));
        $shifts = [];
        for ($resample = 0; $resample < $this->resamples; ++$resample) {
            $baseline = [];
            $candidate = [];
            foreach ($pairs as $pair) {
                $baseline = [
                    ...$baseline,
                    ...self::resample(samples: $pair->baseline, randomizer: $randomizer),
                ];
                $candidate = [
                    ...$candidate,
                    ...self::resample(samples: $pair->candidate, randomizer: $randomizer),
                ];
            }
            $shifts[] = self::shiftPct(
                baseline: SampleCollection::fromArray($baseline),
                candidate: SampleCollection::fromArray($candidate),
            );
        }

        $tail = (1.0 - self::CONFIDENCE) / 2 * 100;

        return new MedianShift(
            estimatePct: $estimate,
            lowerPct: Descriptive::percentile($shifts, $tail),
            upperPct: Descriptive::percentile($shifts, 100 - $tail),
            confidence: self::CONFIDENCE,
            resamples: $this->resamples,
        );
    }

    private static function shiftPct(SampleCollection $baseline, SampleCollection $candidate): float
    {
        return LatencyDelta::percent(
            baseline: Statistics::create($baseline)->median(),
            candidate: Statistics::create($candidate)->median(),
        );
    }

    /**
     * Draws as many samples as the collection holds, with replacement.
     *
     * @return list<int>
     */
    private static function resample(SampleCollection $samples, Randomizer $randomizer): array
    {
        $values = $samples->toNsArray();
        $last = count($values) - 1;
        $drawn = [];
        for ($i = 0; $i <= $last; ++$i) {
            $drawn[] = $values[$randomizer->getInt(0, $last)];
        }

        return $drawn;
    }

    /**
     * @param list<SampleCollection> $collections
     */
    private static function pool(array $collections): SampleCollection
    {
        $pooled = SampleCollection::create([]);
        foreach ($collections as $collection) {
            $pooled = $pooled->merge($collection);
        }

        return $pooled;
    }
}
