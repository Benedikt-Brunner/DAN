<?php

declare(strict_types=1);

namespace Dan\Lib\Time;

/**
 * A monotonic timestamp for measuring elapsed time. It is deliberately not a
 * wall-clock date and must not be persisted or compared across processes.
 */
final readonly class Timestamp
{
    private function __construct(
        private int $nanoseconds,
    ) {}

    public static function now(): self
    {
        return new self(hrtime(true));
    }

    public function durationUntil(self $end): Duration
    {
        return Duration::fromNs($end->nanoseconds - $this->nanoseconds);
    }

    public function elapsed(): Duration
    {
        return $this->durationUntil(self::now());
    }

    public function hasElapsed(Duration $duration): bool
    {
        return $this->elapsed()->isAtLeast($duration);
    }
}
