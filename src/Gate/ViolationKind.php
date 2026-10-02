<?php

declare(strict_types=1);

namespace Dan\Harness\Gate;

enum ViolationKind
{
    /** The two implementations did not return the same result - never optional. */
    case ResultDivergence;
    case SqlChanged;
    case WallRegression;
}
