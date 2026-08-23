<?php

declare(strict_types=1);

namespace Dan\Harness\Tests\Measurement\Result;

use Dan\Harness\Measurement\Result\SampleCollection;
use Dan\Harness\Measurement\Result\Statistics;
use PHPUnit\Framework\TestCase;

final class StatisticsTest extends TestCase
{
    public function testPercentileUsesLinearInterpolationBetweenClosestRanks(): void
    {
        // Pins the percentile definition (R-7, the numpy/Excel default) so a
        // change in the underlying statistics library cannot silently shift
        // reported p95 values between DAN versions.
        self::assertEqualsWithDelta(9.55, $this->statistics(range(1, 10))->percentile(Statistics::P95)->toNsFloat(), 1e-9);
        self::assertSame(2.5, $this->statistics([
            1,
            2,
            3,
            4,
        ])->median()->toNsFloat());
        self::assertSame(3.0, $this->statistics([
            1,
            3,
            7,
        ])->median()->toNsFloat());
    }

    /**
     * @param list<int|float> $samples
     */
    private function statistics(array $samples): Statistics
    {
        return Statistics::create(SampleCollection::fromArray($samples));
    }
}
