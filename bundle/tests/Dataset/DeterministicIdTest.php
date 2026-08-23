<?php

declare(strict_types=1);

namespace Dan\Probe\Tests\Dataset;

use Dan\Probe\Seeding\Dataset\DeterministicId;
use PHPUnit\Framework\TestCase;

final class DeterministicIdTest extends TestCase
{
    public function testSameKeyYieldsSameId(): void
    {
        self::assertSame((string) DeterministicId::create('product:42'), (string) DeterministicId::create('product:42'));
    }

    public function testDifferentKeysYieldDifferentIds(): void
    {
        self::assertNotSame((string) DeterministicId::create('product:42'), (string) DeterministicId::create('product:43'));
    }

    public function testProducesValidUuidV4Shape(): void
    {
        $id = (string) DeterministicId::create('category:0');

        self::assertMatchesRegularExpression('/^[0-9a-f]{12}4[0-9a-f]{3}[89ab][0-9a-f]{15}$/', $id);
    }
}
