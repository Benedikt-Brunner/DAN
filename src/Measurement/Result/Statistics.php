<?php

declare(strict_types=1);

namespace Dan\Harness\Measurement\Result;

use Dan\Lib\Time\Duration;
use InvalidArgumentException;
use MathPHP\Statistics\Average;
use MathPHP\Statistics\Descriptive;

/**
 * DAN's statistics seam. Call sites and tests depend on this interface; the
 * math itself is delegated to MathPHP so statistical methods are never
 * hand-rolled here. Samples are nanosecond durations throughout the pipeline;
 * results remain Duration values (percentiles may contain fractional
 * nanoseconds because they interpolate between samples).
 */
final readonly class Statistics
{
    public const P95 = 95.0;

    private function __construct(
        private SampleCollection $samples,
    ) {}

    public static function create(SampleCollection $samples): self
    {
        self::assertNotEmpty($samples);

        return new self($samples);
    }

    public function median(): Duration
    {
        return Duration::fromNs(Average::median($this->rawSamples()));
    }

    public function percentile(float $percentile): Duration
    {
        return Duration::fromNs(Descriptive::percentile($this->rawSamples(), $percentile));
    }

    /**
     * @return list<float>
     */
    private function rawSamples(): array
    {
        return array_map(
            fn (Sample $sample): float => $sample->duration()->toNsFloat(),
            $this->samples->getItems(),
        );
    }

    private static function assertNotEmpty(SampleCollection $samples): void
    {
        if ($samples->empty()) {
            throw new InvalidArgumentException('Cannot compute a percentile of zero samples.');
        }
    }
}
