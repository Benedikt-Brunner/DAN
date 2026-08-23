<?php

declare(strict_types=1);

namespace Dan\Harness\Tests\Time;

use Dan\Lib\Time\Duration;
use Dan\Lib\Time\Timestamp;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;

final class DurationTest extends TestCase
{
    public function testConvertsNanosecondsToMilliseconds(): void
    {
        $duration = Duration::fromNs(1_500_000);

        self::assertSame(1_500_000, $duration->toNsInt());
        self::assertSame(1_500_000.0, $duration->toNsFloat());
        self::assertSame(1.5, $duration->toMsFloat());
        self::assertSame(0.0015, $duration->toSecondsFloat());
    }

    public function testCreatesDurationFromSeconds(): void
    {
        self::assertSame(1_500.0, Duration::fromSeconds(1.5)->toMsFloat());
    }

    public function testComparesDurations(): void
    {
        self::assertTrue(Duration::fromNs(2)->isAtLeast(Duration::fromNs(1)));
        self::assertTrue(Duration::fromNs(2)->isAtLeast(Duration::fromNs(2)));
        self::assertFalse(Duration::fromNs(1)->isAtLeast(Duration::fromNs(2)));
    }

    public function testSleepsForDuration(): void
    {
        $startedAt = Timestamp::now();
        $sleepDuration = Duration::fromSeconds(0.001);

        $sleepDuration->sleep();

        self::assertTrue($startedAt->elapsed()->isAtLeast($sleepDuration));
    }

    public function testRetainsFractionalNanoseconds(): void
    {
        $duration = Duration::fromNs(2.5);

        self::assertSame(2.5, $duration->toNsFloat());
        $this->expectException(LogicException::class);
        $duration->toNsInt();
    }

    public function testRejectsNegativeDurations(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Duration::fromNs(-1);
    }

    public function testRejectsNonFiniteDurations(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Duration::fromNs(\INF);
    }
}
