<?php

declare(strict_types=1);

namespace Dan\Lib\Protocol;

enum DatasetFingerprintSchemaVersion: int
{
    case V1 = 1;

    public static function getCurrent(): self
    {
        return self::V1;
    }
}
