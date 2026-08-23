<?php

declare(strict_types=1);

namespace Dan\Probe\Tests\Dataset;

use Dan\Lib\Protocol\Tier;
use Dan\Probe\Seeding\Dataset\TierSpec;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TierSpecTest extends TestCase
{
    /**
     * @return iterable<string, array{Tier, int, int, int}>
     */
    public static function tiers(): iterable
    {
        yield 'small' => [
            Tier::S,
            1_000,
            50,
            1_000,
        ];
        yield 'medium' => [
            Tier::M,
            100_000,
            500,
            100_000,
        ];
        yield 'large' => [
            Tier::L,
            1_000_000,
            2_000,
            1_000_000,
        ];
    }

    #[DataProvider('tiers')]
    public function testDefinesDatasetShape(
        Tier $tier,
        int $products,
        int $categories,
        int $syntheticBlobs,
    ): void {
        $spec = TierSpec::forTier($tier);

        self::assertSame($tier, $spec->tier);
        self::assertSame($products, $spec->products);
        self::assertSame($categories, $spec->categories);
        self::assertSame($syntheticBlobs, $spec->syntheticBlobs);
    }
}
