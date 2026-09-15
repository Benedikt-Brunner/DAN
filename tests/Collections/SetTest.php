<?php

declare(strict_types=1);

namespace Dan\Harness\Tests\Collections;

use Dan\Lib\Collections\Set;
use PHPUnit\Framework\TestCase;

final class SetTest extends TestCase
{
    public function testCollapsesDuplicatesOntoTheirFirstOccurrence(): void
    {
        $set = Set::create([
            'first',
            'second',
            'first',
            'third',
            'second',
        ]);

        self::assertCount(3, $set);
        self::assertSame([
            'first',
            'second',
            'third',
        ], iterator_to_array($set));
    }

    public function testDistinguishesMembersByStrictIdentity(): void
    {
        $set = Set::create([
            1,
            '1',
            true,
        ]);

        self::assertCount(3, $set);
        self::assertTrue($set->contains(1));
        self::assertTrue($set->contains('1'));
        self::assertFalse($set->contains(1.0));
        self::assertFalse($set->contains('2'));
    }

    public function testWithReturnsAnExtendedSetAndLeavesTheReceiverUntouched(): void
    {
        $empty = Set::create([]);

        $one = $empty->with('first');
        $two = $one->with('second');

        self::assertTrue($empty->empty());
        self::assertFalse($empty->contains('first'));
        self::assertSame(['first'], iterator_to_array($one));
        self::assertSame([
            'first',
            'second',
        ], iterator_to_array($two));
    }

    public function testWithAnExistingMemberChangesNothing(): void
    {
        $set = Set::create([
            'first',
            'second',
        ]);

        $same = $set->with('first');

        self::assertCount(2, $same);
        self::assertSame([
            'first',
            'second',
        ], iterator_to_array($same));
    }
}
