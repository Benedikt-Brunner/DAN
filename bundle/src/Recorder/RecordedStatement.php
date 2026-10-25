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
 *
 * Types are the DBAL binding types (ParameterType constants) the DAL gave
 * each parameter, keyed like the parameters - null when the statement was executed with its values passed
 * straight to execute(), which binds every value as a string.
 */
final class RecordedStatement
{
    /**
     * @param array<mixed>|null $params
     * @param array<int|string, int>|null $types
     */
    public function __construct(
        public readonly string $sql,
        public readonly ?array $params,
        public readonly ?array $types,
        public readonly Duration $duration,
    ) {}
}
