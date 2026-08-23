<?php

declare(strict_types=1);

namespace Dan\Harness\RunStore\Artifact;

enum CellResultSchemaVersion: int
{
    case V1 = 1;

    public static function getCurrent(): self
    {
        return self::V1;
    }
}
