<?php

declare(strict_types=1);

namespace Dan\Lib\Protocol;

/**
 * Dataset tier of a grid cell. This is shared so the harness and probe cannot
 * silently disagree on the values accepted across their CLI boundary.
 */
enum Tier: string
{
    case S = 'S';
    case M = 'M';
    case L = 'L';
}
