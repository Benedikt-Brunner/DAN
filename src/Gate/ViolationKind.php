<?php

declare(strict_types=1);

namespace Dan\Harness\Gate;

enum ViolationKind
{
    case SqlChanged;
    case WallRegression;
}
