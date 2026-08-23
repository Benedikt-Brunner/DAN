<?php

declare(strict_types=1);

namespace Dan\Harness\Tests\Lib\Protocol;

use Dan\Lib\Protocol\ScenarioResultSchemaVersion;
use PHPUnit\Framework\TestCase;

final class ScenarioResultSchemaVersionTest extends TestCase
{
    public function testSchemaStartsAtVersionOne(): void
    {
        self::assertSame(ScenarioResultSchemaVersion::V1, ScenarioResultSchemaVersion::getCurrent());
        self::assertSame(1, ScenarioResultSchemaVersion::getCurrent()->value);
    }
}
