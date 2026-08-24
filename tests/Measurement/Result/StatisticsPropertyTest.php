<?php

declare(strict_types=1);

namespace Dan\Harness\Tests\Measurement\Result;

use Dan\Harness\Measurement\Result\SampleCollection;
use Dan\Harness\Measurement\Result\Statistics;
use Dan\Harness\Tests\DomainGenerators;
use Dan\Harness\Tests\PropertyTestCase;
use Eris\Generator;
use LogicException;

/**
 * Bounds, monotonicity and order-independence of the statistics DAN reports.
 * Every latency number in a diff goes through this seam.
 */
final class StatisticsPropertyTest extends PropertyTestCase
{
    public function testMedianAndPercentilesStayWithinTheSampleBounds(): void
    {
        $this->forAll(DomainGenerators::samples(), Generator\choose(0, 100))->then(function (mixed $samples, int $percentile): void {
            $samples = self::nonEmptyIntList($samples);
            $statistics = Statistics::create(SampleCollection::fromArray($samples));

            self::assertGreaterThanOrEqual(min($samples), $statistics->median()->toNsFloat());
            self::assertLessThanOrEqual(max($samples), $statistics->median()->toNsFloat());
            self::assertGreaterThanOrEqual(min($samples), $statistics->percentile((float) $percentile)->toNsFloat());
            self::assertLessThanOrEqual(max($samples), $statistics->percentile((float) $percentile)->toNsFloat());
        });
    }

    public function testPercentilesAreMonotoneInThePercentileRank(): void
    {
        $this->forAll(
            DomainGenerators::samples(),
            Generator\choose(0, 100),
            Generator\choose(0, 100),
        )->then(function (mixed $samples, int $lower, int $upper): void {
            $samples = self::nonEmptyIntList($samples);
            if ($lower > $upper) {
                [
                    $lower,
                    $upper,
                ] = [
                    $upper,
                    $lower,
                ];
            }
            $statistics = Statistics::create(SampleCollection::fromArray($samples));

            self::assertLessThanOrEqual(
                $statistics->percentile((float) $upper)->toNsFloat(),
                $statistics->percentile((float) $lower)->toNsFloat(),
            );
        });
    }

    public function testMedianAndPercentileAreIndependentOfSampleOrder(): void
    {
        $this->forAll(DomainGenerators::samples(), Generator\choose(0, 100))->then(function (mixed $samples, int $percentile): void {
            $samples = self::nonEmptyIntList($samples);
            $shuffled = $samples;
            shuffle($shuffled);

            $original = Statistics::create(SampleCollection::fromArray($samples));
            $reordered = Statistics::create(SampleCollection::fromArray($shuffled));

            self::assertSame($original->median()->toNsFloat(), $reordered->median()->toNsFloat());
            self::assertSame(
                $original->percentile((float) $percentile)->toNsFloat(),
                $reordered->percentile((float) $percentile)->toNsFloat(),
            );
        });
    }

    public function testMergedSampleCollectionsCarryEverySampleFromBothSidesInAnyOrder(): void
    {
        $this->forAll(DomainGenerators::samples(), DomainGenerators::samples())->then(function (mixed $left, mixed $right): void {
            $left = self::nonEmptyIntList($left);
            $right = self::nonEmptyIntList($right);
            $leftCollection = SampleCollection::fromArray($left);
            $rightCollection = SampleCollection::fromArray($right);

            $leftFirst = $leftCollection->merge($rightCollection)->toNsArray();
            $rightFirst = $rightCollection->merge($leftCollection)->toNsArray();

            self::assertCount(count($left) + count($right), $leftFirst);
            sort($leftFirst);
            sort($rightFirst);
            self::assertSame($leftFirst, $rightFirst);

            $expected = [
                ...$left,
                ...$right,
            ];
            sort($expected);
            self::assertSame($expected, $leftFirst);
        });
    }

    /**
     * @return non-empty-list<int>
     */
    private static function nonEmptyIntList(mixed $value): array
    {
        $list = DomainGenerators::asIntList($value);
        if ($list === []) {
            throw new LogicException('Generated sample list is empty.');
        }

        return $list;
    }
}
