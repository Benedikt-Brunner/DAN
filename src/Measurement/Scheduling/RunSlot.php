<?php

declare(strict_types=1);

namespace Dan\Harness\Measurement\Scheduling;

enum RunSlot: string
{
    case Baseline = 'baseline';
    case Candidate = 'candidate';
}
