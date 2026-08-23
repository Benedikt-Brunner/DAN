<?php

declare(strict_types=1);

namespace Dan\Harness\Tests\Comparison;

use Dan\Harness\Comparison\SqlNormalizer;
use PHPUnit\Framework\TestCase;

final class SqlNormalizerTest extends TestCase
{
    public function testCollapsesWhitespace(): void
    {
        $normalizer = new SqlNormalizer();

        self::assertSame(
            'SELECT id FROM product WHERE stock > ?',
            $normalizer->normalize("SELECT   id\nFROM product\n  WHERE stock > ?"),
        );
    }

    public function testCollapsesPositionalInListsRegardlessOfArity(): void
    {
        $normalizer = new SqlNormalizer();

        $three = $normalizer->normalize('SELECT id FROM product WHERE id IN (?, ?, ?)');
        $five = $normalizer->normalize('SELECT id FROM product WHERE id IN (?,?,?,?,?)');

        self::assertSame($three, $five);
        self::assertSame('SELECT id FROM product WHERE id IN (?...)', $three);
    }

    public function testCollapsesNamedInLists(): void
    {
        $normalizer = new SqlNormalizer();

        $two = $normalizer->normalize('SELECT id FROM product WHERE id IN (:id1, :id2)');
        $four = $normalizer->normalize('SELECT id FROM product WHERE id IN (:id1, :id2, :id3, :id4)');

        self::assertSame($two, $four);
    }

    public function testKeepsSinglePlaceholderGroupsIntact(): void
    {
        $normalizer = new SqlNormalizer();

        self::assertSame(
            'SELECT id FROM product WHERE id = (?)',
            $normalizer->normalize('SELECT id FROM product WHERE id = (?)'),
        );
    }
}
