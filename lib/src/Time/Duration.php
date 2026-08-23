<?php

declare(strict_types=1);

namespace Dan\Lib\Time;

use InvalidArgumentException;
use LogicException;
use RuntimeException;

final readonly class Duration
{
    private const NANOSECONDS_PER_MILLISECOND = 1_000_000;
    private const NANOSECONDS_PER_SECOND = 1_000_000_000;

    private function __construct(
        private int|float $nanoseconds,
    ) {}

    public static function fromNs(int|float $nanoseconds): self
    {
        if ($nanoseconds < 0 || (is_float($nanoseconds) && !is_finite($nanoseconds))) {
            throw new InvalidArgumentException('A duration must be a finite, non-negative number of nanoseconds.');
        }

        return new self($nanoseconds);
    }

    public static function fromSeconds(int|float $seconds): self
    {
        return self::fromNs($seconds * self::NANOSECONDS_PER_SECOND);
    }

    public function toNsFloat(): float
    {
        return (float) $this->nanoseconds;
    }

    public function toNsInt(): int
    {
        if (!is_int($this->nanoseconds)) {
            throw new LogicException('A fractional duration cannot be converted to integer nanoseconds without losing precision.');
        }

        return $this->nanoseconds;
    }

    public function toMsFloat(): float
    {
        return $this->nanoseconds / self::NANOSECONDS_PER_MILLISECOND;
    }

    public function toSecondsFloat(): float
    {
        return $this->nanoseconds / self::NANOSECONDS_PER_SECOND;
    }

    public function sleep(): void
    {
        $seconds = (int) floor($this->toSecondsFloat());
        $nanoseconds = (int) round($this->nanoseconds - ($seconds * self::NANOSECONDS_PER_SECOND));
        if ($nanoseconds === self::NANOSECONDS_PER_SECOND) {
            ++$seconds;
            $nanoseconds = 0;
        }

        while (($remaining = time_nanosleep($seconds, $nanoseconds)) !== true) {
            if ($remaining === false) {
                throw new RuntimeException('Sleeping for the requested duration failed.');
            }

            $seconds = $remaining['seconds'];
            $nanoseconds = $remaining['nanoseconds'];
        }
    }

    public function isAtLeast(self $other): bool
    {
        return $this->nanoseconds >= $other->nanoseconds;
    }
}
