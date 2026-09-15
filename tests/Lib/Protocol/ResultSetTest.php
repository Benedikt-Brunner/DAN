<?php

declare(strict_types=1);

namespace Dan\Harness\Tests\Lib\Protocol;

use Dan\Lib\Protocol\ResultSet;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ResultSetTest extends TestCase
{
    public function testEqualityIsOrderSensitiveAndSameIdsIsNot(): void
    {
        $ab = new ResultSet(ids: [
            'a',
            'b',
        ], total: 2);
        $ba = new ResultSet(ids: [
            'b',
            'a',
        ], total: 2);

        self::assertTrue($ab->equals(new ResultSet(ids: [
            'a',
            'b',
        ], total: 2)));
        self::assertFalse($ab->equals($ba));
        self::assertTrue($ab->sameIds($ba));
        self::assertFalse($ab->sameIds(new ResultSet(ids: ['a'], total: 1)));
    }

    public function testTheTotalIsPartOfTheResult(): void
    {
        $page = new ResultSet(ids: ['a'], total: 10);

        self::assertFalse($page->equals(new ResultSet(ids: ['a'], total: 11)));
    }

    public function testEmptyResultsAreEqual(): void
    {
        self::assertTrue((new ResultSet(ids: [], total: 0))->equals(new ResultSet(ids: [], total: 0)));
    }

    public function testRoundTripsThroughDecodedPayloads(): void
    {
        $resultSet = new ResultSet(ids: [
            'a',
            'b',
        ], total: 7);

        self::assertSame($resultSet->toArray(), ResultSet::fromDecodedArray($resultSet->toArray())->toArray());
        self::assertTrue(ResultSet::fromArray($resultSet->toArray())->equals($resultSet));
    }

    public function testRefusesANegativeTotal(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ResultSet(ids: [], total: -1);
    }

    public function testRefusesNonStringIds(): void
    {
        $this->expectException(RuntimeException::class);
        ResultSet::fromDecodedArray([
            'ids' => [1],
            'total' => 1,
        ]);
    }

    public function testRefusesAMissingTotal(): void
    {
        $this->expectException(RuntimeException::class);
        ResultSet::fromDecodedArray(['ids' => []]);
    }
}
