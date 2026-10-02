<?php

declare(strict_types=1);

namespace Dan\Harness\Tests\Measurement\Result;

use Dan\Harness\Measurement\Result\MedianShift;
use Dan\Harness\Measurement\Result\MedianShiftEstimator;
use Dan\Harness\Measurement\Result\SampleCollection;
use Dan\Harness\Measurement\Result\SamplePair;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class MedianShiftEstimatorTest extends TestCase
{
    public function testTheEstimateIsTheRelativeShiftOfThePooledMedians(): void
    {
        $shift = (new MedianShiftEstimator(resamples: 50))->estimate([
            self::pair(baseline: [
                10,
                20,
                30,
            ], candidate: [
                20,
                40,
                60,
            ]),
        ]);

        self::assertSame(100.0, $shift->estimatePct);
        self::assertSame(50, $shift->resamples);
        self::assertSame(MedianShiftEstimator::CONFIDENCE, $shift->confidence);
    }

    public function testConstantSamplesYieldADegenerateIntervalAtTheEstimate(): void
    {
        // Every resample of a constant distribution is that constant, so the
        // whole bootstrap distribution collapses onto the point estimate.
        $shift = (new MedianShiftEstimator(resamples: 20))->estimate([
            self::pair(baseline: [
                10,
                10,
                10,
            ], candidate: [
                12,
                12,
                12,
            ]),
        ]);

        self::assertSame(20.0, $shift->estimatePct);
        self::assertSame(20.0, $shift->lowerPct);
        self::assertSame(20.0, $shift->upperPct);
        self::assertTrue($shift->excludesZero());
    }

    public function testIdenticalDistributionsKeepZeroInsideTheInterval(): void
    {
        $samples = [
            1_000_000,
            1_100_000,
            950_000,
            1_050_000,
            1_200_000,
            980_000,
            1_010_000,
            1_090_000,
        ];

        $shift = (new MedianShiftEstimator(resamples: 200))->estimate([self::pair(baseline: $samples, candidate: $samples)]);

        self::assertSame(0.0, $shift->estimatePct);
        self::assertLessThanOrEqual(0.0, $shift->lowerPct);
        self::assertGreaterThanOrEqual(0.0, $shift->upperPct);
        self::assertFalse($shift->excludesZero());
        self::assertFalse($shift->isRegressionBeyond(0.0));
    }

    public function testResamplingIsReproducible(): void
    {
        $pairs = [
            self::pair(baseline: [
                100,
                130,
                90,
                110,
            ], candidate: [
                120,
                150,
                95,
                140,
            ]),
            self::pair(baseline: [
                105,
                125,
                92,
            ], candidate: [
                118,
                160,
                99,
            ]),
        ];

        $first = (new MedianShiftEstimator(resamples: 100))->estimate($pairs);
        $second = (new MedianShiftEstimator(resamples: 100))->estimate($pairs);

        self::assertSame($first->lowerPct, $second->lowerPct);
        self::assertSame($first->upperPct, $second->upperPct);
        self::assertLessThanOrEqual($first->upperPct, $first->estimatePct);
        self::assertGreaterThanOrEqual($first->lowerPct, $first->estimatePct);
    }

    public function testRefusesAnEmptyPairList(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new MedianShiftEstimator(resamples: 10))->estimate([]);
    }

    public function testRefusesFewerThanTwoResamples(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new MedianShiftEstimator(resamples: 1);
    }

    public function testAPairRefusesAnEmptySide(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SamplePair(baseline: SampleCollection::fromArray([1]), candidate: SampleCollection::fromArray([]));
    }

    public function testAShiftRefusesAnInvertedInterval(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new MedianShift(estimatePct: 1.0, lowerPct: 2.0, upperPct: 1.0, confidence: 0.95, resamples: 10);
    }

    public function testAShiftRefusesAnImpossibleConfidence(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new MedianShift(estimatePct: 1.0, lowerPct: 0.0, upperPct: 2.0, confidence: 1.0, resamples: 10);
    }

    public function testRegressionNeedsBothSignificanceAndMagnitude(): void
    {
        $significantAndLarge = new MedianShift(estimatePct: 20.0, lowerPct: 12.0, upperPct: 28.0, confidence: 0.95, resamples: 10);
        $largeButUncertain = new MedianShift(estimatePct: 20.0, lowerPct: -3.0, upperPct: 43.0, confidence: 0.95, resamples: 10);
        $significantButSmall = new MedianShift(estimatePct: 4.0, lowerPct: 1.0, upperPct: 7.0, confidence: 0.95, resamples: 10);
        $improvement = new MedianShift(estimatePct: -20.0, lowerPct: -28.0, upperPct: -12.0, confidence: 0.95, resamples: 10);

        self::assertTrue($significantAndLarge->isRegressionBeyond(15.0));
        self::assertFalse($largeButUncertain->isRegressionBeyond(15.0));
        self::assertFalse($significantButSmall->isRegressionBeyond(15.0));
        self::assertTrue($improvement->excludesZero());
        self::assertFalse($improvement->isRegressionBeyond(15.0));
    }

    /**
     * @param list<int> $baseline
     * @param list<int> $candidate
     */
    private static function pair(array $baseline, array $candidate): SamplePair
    {
        return new SamplePair(baseline: SampleCollection::fromArray($baseline), candidate: SampleCollection::fromArray($candidate));
    }
}
