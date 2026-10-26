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
     * @return iterable<string, array{Tier, int, int, int, int, int, int}>
     */
    public static function tiers(): iterable
    {
        yield 'small' => [
            Tier::S,
            1_000,
            100,
            50,
            500,
            250,
            1_000,
        ];
        yield 'medium' => [
            Tier::M,
            100_000,
            10_000,
            500,
            50_000,
            25_000,
            100_000,
        ];
        yield 'large' => [
            Tier::L,
            1_000_000,
            100_000,
            2_000,
            500_000,
            250_000,
            1_000_000,
        ];
    }

    #[DataProvider('tiers')]
    public function testDefinesDatasetShape(
        Tier $tier,
        int $products,
        int $variants,
        int $categories,
        int $reviews,
        int $media,
        int $syntheticBlobs,
    ): void {
        $spec = TierSpec::forTier($tier);

        self::assertSame($tier, $spec->tier);
        self::assertSame($products, $spec->products);
        self::assertSame($variants, $spec->variants());
        self::assertSame($categories, $spec->categories);
        self::assertSame($reviews, $spec->reviews);
        self::assertSame($media, $spec->media);
        self::assertSame($syntheticBlobs, $spec->syntheticBlobs);
        // Every review points at an existing product.
        self::assertLessThan($products, $spec->reviewProduct($reviews - 1));
    }

    public function testCountsParentsAndVariantsSeparately(): void
    {
        $spec = TierSpec::forTier(Tier::S);

        self::assertSame(1_100, $spec->countProducts(fn (int $index, bool $variant): bool => true));
        self::assertSame(100, $spec->countProducts(fn (int $index, bool $variant): bool => $variant));
        self::assertSame(1_000, $spec->countProducts(fn (int $index, bool $variant): bool => !$variant));
        self::assertTrue(TierSpec::hasVariant(0));
        self::assertTrue(TierSpec::hasVariant(990));
        self::assertFalse(TierSpec::hasVariant(995));
    }

    public function testDatasetRulesAreDeterministicFunctionsOfTheIndex(): void
    {
        $spec = TierSpec::forTier(Tier::S);

        self::assertSame(7, $spec->productStock(1_007));
        self::assertSame(7, $spec->productCategory(57));
        self::assertSame(84, $spec->reviewProduct(42));
        self::assertSame(3, TierSpec::reviewPoints(7));
        self::assertSame('png', TierSpec::mediaExtension(4));
        self::assertSame('pdf', TierSpec::mediaExtension(7));
        self::assertSame('segment-07', TierSpec::blobSegment(23));
        self::assertSame(23, TierSpec::blobScore(1_023));
        self::assertTrue(TierSpec::blobActive(9));
        self::assertFalse(TierSpec::blobActive(10));
    }
}
