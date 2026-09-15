<?php

declare(strict_types=1);

namespace Dan\Harness\Tests\Measurement\Result;

use Dan\Harness\Measurement\Result\LatencyDelta;
use Dan\Harness\Measurement\Result\MedianShiftEstimator;
use Dan\Harness\Measurement\Result\SampleCollection;
use Dan\Harness\Measurement\Result\SamplePair;
use Dan\Harness\Measurement\Result\Statistics;
use Dan\Harness\Tests\DomainGenerators;
use Dan\Harness\Tests\PropertyTestCase;
use Eris\Generator;

/**
 * The two claims the distribution-aware gate rests on: identical
 * distributions never register as a regression, and a distribution shifted
 * well beyond the threshold always does once there are enough samples.
 * Latency-shaped samples (a base value with bounded relative dispersion)
 * stand in for what a warm-cache scenario actually produces.
 */
final class MedianShiftPropertyTest extends PropertyTestCase
{
    private const float THRESHOLD_PCT = 15.0;

    public function testIdenticalDistributionsNeverRegress(): void
    {
        $this->forAll(
            DomainGenerators::boundedList(elements: $this->latencyBlock(), maxLength: 3),
            Generator\choose(0, 2),
        )->then(function (mixed $blocks, int $thresholdSeed): void {
            $pairs = [];
            foreach (DomainGenerators::asList($blocks) as $block) {
                $samples = SampleCollection::fromArray(DomainGenerators::asIntList($block));
                $pairs[] = new SamplePair(baseline: $samples, candidate: $samples);
            }

            $shift = (new MedianShiftEstimator(resamples: 100))->estimate($pairs);

            self::assertSame(0.0, $shift->estimatePct);
            self::assertFalse($shift->excludesZero(), 'Resampling one distribution against itself must keep zero inside the interval.');
            // Whatever the threshold - even zero - identical data never fails.
            self::assertFalse($shift->isRegressionBeyond((float) $thresholdSeed * self::THRESHOLD_PCT));
        });
    }

    public function testADistributionShiftedBeyondTheThresholdAlwaysRegresses(): void
    {
        $this->forAll(
            DomainGenerators::boundedList(elements: $this->latencyBlock(), maxLength: 3),
            Generator\choose(150, 300),
        )->then(function (mixed $blocks, int $factorPct): void {
            // The candidate is every baseline sample scaled by at least 1.5x:
            // a shift of >= 50% against a 15% threshold.
            $pairs = [];
            foreach (DomainGenerators::asList($blocks) as $block) {
                $baseline = DomainGenerators::asIntList($block);
                $candidate = array_map(fn (int $sample): int => intdiv($sample * $factorPct, 100), $baseline);
                $pairs[] = new SamplePair(baseline: SampleCollection::fromArray($baseline), candidate: SampleCollection::fromArray($candidate));
            }

            $shift = (new MedianShiftEstimator(resamples: 100))->estimate($pairs);

            self::assertGreaterThan(self::THRESHOLD_PCT, $shift->estimatePct);
            self::assertTrue($shift->excludesZero());
            self::assertTrue($shift->isRegressionBeyond(self::THRESHOLD_PCT));
        });
    }

    public function testTheEstimateIsThePooledMedianShiftAndLiesInsideItsInterval(): void
    {
        $this->forAll(
            DomainGenerators::boundedList(elements: $this->latencyBlock(), maxLength: 3),
            DomainGenerators::boundedList(elements: $this->latencyBlock(), maxLength: 3),
        )->then(function (mixed $baselineBlocks, mixed $candidateBlocks): void {
            $baselineBlocks = DomainGenerators::asList($baselineBlocks);
            $candidateBlocks = DomainGenerators::asList($candidateBlocks);
            $pairs = [];
            $pooledBaseline = [];
            $pooledCandidate = [];
            foreach ($baselineBlocks as $index => $block) {
                $baseline = DomainGenerators::asIntList($block);
                $candidate = DomainGenerators::asIntList($candidateBlocks[$index % count($candidateBlocks)]);
                $pairs[] = new SamplePair(baseline: SampleCollection::fromArray($baseline), candidate: SampleCollection::fromArray($candidate));
                $pooledBaseline = [
                    ...$pooledBaseline,
                    ...$baseline,
                ];
                $pooledCandidate = [
                    ...$pooledCandidate,
                    ...$candidate,
                ];
            }

            $shift = (new MedianShiftEstimator(resamples: 100))->estimate($pairs);

            $expected = LatencyDelta::percent(
                baseline: Statistics::create(SampleCollection::fromArray($pooledBaseline))->median(),
                candidate: Statistics::create(SampleCollection::fromArray($pooledCandidate))->median(),
            );
            self::assertSame($expected, $shift->estimatePct);
            self::assertLessThanOrEqual($shift->upperPct, $shift->lowerPct);
        });
    }

    /**
     * One block of warm-cache latency samples: 30 to 60 draws around a base
     * value with at most +-20% dispersion.
     *
     * @return Generator<mixed>
     */
    private function latencyBlock(): Generator
    {
        return Generator\bind(
            Generator\tuple(
                Generator\choose(500_000, 50_000_000),
                Generator\choose(30, 60),
            ),
            self::samplesAround(...),
        );
    }

    /**
     * @param array<mixed> $parts
     *
     * @return Generator<mixed>
     */
    private static function samplesAround(array $parts): Generator
    {
        $base = DomainGenerators::asInt($parts[0]);
        $count = DomainGenerators::asInt($parts[1]);

        return Generator\vector($count, Generator\choose(intdiv($base * 80, 100), intdiv($base * 120, 100)));
    }
}
