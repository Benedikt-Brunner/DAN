<?php

declare(strict_types=1);

namespace Dan\Probe\Recorder;

use Dan\Lib\Time\Duration;

/**
 * One statement captured by a recorder. This is the contract every recorder
 * implementation drains into - the planned DBAL 4 driver-middleware recorder
 * must produce the same shape.
 *
 * Durations retain the integer nanoseconds measured by the monotonic clock;
 * conversion to an artifact scalar happens only at the CLI boundary.
 */
final class RecordedStatement
{
    /**
     * @param array<mixed>|null $params
     */
    public function __construct(
        public readonly string $sql,
        public readonly ?array $params,
        public readonly Duration $duration,
    ) {}
}
