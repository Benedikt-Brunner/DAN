<?php

declare(strict_types=1);

namespace Dan\Harness\Tests\Collections;

use BadMethodCallException;
use Dan\Lib\Collections\Collection;
use PHPUnit\Framework\TestCase;

final class CollectionTest extends TestCase
{
    public function testExposesItemsThroughItsReadInterfaces(): void
    {
        $collection = Collection::create([
            'first',
            null,
            'third',
        ]);

        self::assertCount(3, $collection);
        self::assertSame('first', $collection[0]);
        self::assertTrue(isset($collection[1]));
        self::assertFalse(isset($collection[3]));
        self::assertSame([
            'first',
            null,
            'third',
        ], iterator_to_array($collection));
        self::assertSame('["first",null,"third"]', json_encode($collection, \JSON_THROW_ON_ERROR));
    }

    public function testItemsCannotBeSet(): void
    {
        $collection = Collection::create(['first']);

        $this->expectException(BadMethodCallException::class);

        $collection[0] = 'replacement';
    }

    public function testItemsCannotBeUnset(): void
    {
        $collection = Collection::create(['first']);

        $this->expectException(BadMethodCallException::class);

        unset($collection[0]);
    }
}
