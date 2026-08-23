<?php

declare(strict_types=1);

namespace Dan\Harness\Tests\RunStore;

use Dan\Harness\RunStore\Artifact\CellResultSchemaVersion;
use PHPUnit\Framework\TestCase;

final class CellResultSchemaVersionTest extends TestCase
{
    public function testSchemaStartsAtVersionOne(): void
    {
        self::assertSame(CellResultSchemaVersion::V1, CellResultSchemaVersion::getCurrent());
        self::assertSame(1, CellResultSchemaVersion::getCurrent()->value);
    }
}
