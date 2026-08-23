<?php

declare(strict_types=1);

namespace Dan\Harness\Tests\Time;

use Dan\Lib\Time\Duration;
use Dan\Lib\Time\Timestamp;
use PHPUnit\Framework\TestCase;

final class TimestampTest extends TestCase
{
    public function testMeasuresElapsedMonotonicTime(): void
    {
        $startedAt = Timestamp::now();

        self::assertGreaterThanOrEqual(0, $startedAt->elapsed()->toNsInt());
    }

    public function testReportsWhenDurationHasElapsed(): void
    {
        self::assertTrue(Timestamp::now()->hasElapsed(Duration::fromNs(0)));
    }
}
