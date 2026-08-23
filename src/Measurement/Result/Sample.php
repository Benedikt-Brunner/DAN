<?php

declare(strict_types=1);

namespace Dan\Harness\Measurement\Result;

use Dan\Lib\Time\Duration;

final readonly class Sample
{
    private function __construct(
        private Duration $duration,
    ) {}

    public static function create(Duration $duration): self
    {
        return new self($duration);
    }

    public function duration(): Duration
    {
        return $this->duration;
    }
}
