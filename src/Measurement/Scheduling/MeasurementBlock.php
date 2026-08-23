<?php

declare(strict_types=1);

namespace Dan\Harness\Measurement\Scheduling;

final class MeasurementBlock
{
    public function __construct(
        public readonly RunSlot $slot,
        public readonly int $iterations,
        public readonly int $blockIndex,
    ) {}
}
