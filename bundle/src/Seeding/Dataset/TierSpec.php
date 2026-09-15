<?php

declare(strict_types=1);

namespace Dan\Probe\Seeding\Dataset;

use Dan\Lib\Protocol\Tier;

/**
 * The shape of a dataset tier: row counts plus the deterministic rules that
 * relate the seeded rows to each other. Scenarios derive their expected
 * result cardinality from the same rules the seeder writes with, so an
 * expectation and the data it describes can never drift apart.
 *
 * Changing any number or rule here alters seeder output, so
 * SnapshotCache::SEEDER_VERSION must be bumped at the same time.
 */
final class TierSpec
{
    /** Every tenth product is a variant parent with exactly one child. */
    public const VARIANT_EVERY = 10;

    /** Review r belongs to product 2r; even products carry one review each. */
    private const REVIEW_PRODUCT_STRIDE = 2;

    private const MEDIA_EXTENSIONS = [
        'png',
        'jpg',
        'webp',
        'pdf',
    ];

    public function __construct(
        public readonly Tier $tier,
        public readonly int $products,
        public readonly int $categories,
        public readonly int $syntheticBlobs,
        public readonly int $reviews,
        public readonly int $media,
    ) {}

    public static function forTier(Tier $tier): self
    {
        return match ($tier) {
            Tier::S => new self(tier: $tier, products: 1_000, categories: 50, syntheticBlobs: 1_000, reviews: 500, media: 250),
            Tier::M => new self(tier: $tier, products: 100_000, categories: 500, syntheticBlobs: 100_000, reviews: 50_000, media: 25_000),
            Tier::L => new self(tier: $tier, products: 1_000_000, categories: 2_000, syntheticBlobs: 1_000_000, reviews: 500_000, media: 250_000),
        };
    }

    public static function hasVariant(int $productIndex): bool
    {
        return $productIndex % self::VARIANT_EVERY === 0;
    }

    public function variants(): int
    {
        return intdiv($this->products - 1, self::VARIANT_EVERY) + 1;
    }

    public function productStock(int $productIndex): int
    {
        return $productIndex % 1_000;
    }

    public function productCategory(int $productIndex): int
    {
        return $productIndex % $this->categories;
    }

    public function reviewProduct(int $reviewIndex): int
    {
        return $reviewIndex * self::REVIEW_PRODUCT_STRIDE;
    }

    public static function reviewPoints(int $reviewIndex): int
    {
        return $reviewIndex % 5 + 1;
    }

    public static function mediaExtension(int $mediaIndex): string
    {
        return self::MEDIA_EXTENSIONS[$mediaIndex % count(self::MEDIA_EXTENSIONS)];
    }

    public static function blobSegment(int $blobIndex): string
    {
        return sprintf('segment-%02d', $blobIndex % 16);
    }

    public static function blobScore(int $blobIndex): int
    {
        return $blobIndex % 1_000;
    }

    public static function blobActive(int $blobIndex): bool
    {
        return $blobIndex % 3 === 0;
    }

    /**
     * Counts the seeded products - parents and, where present, their variant
     * child - the predicate accepts. Variants inherit name, tax and
     * categories from their parent and share its stock; they have their own
     * product number and EAN.
     *
     * @param callable(int $productIndex, bool $variant): bool $matches
     */
    public function countProducts(callable $matches): int
    {
        $count = 0;
        for ($index = 0; $index < $this->products; ++$index) {
            if ($matches($index, false)) {
                ++$count;
            }
            if (self::hasVariant($index) && $matches($index, true)) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * @param callable(int $reviewIndex, int $productIndex): bool $matches
     */
    public function countReviews(callable $matches): int
    {
        $count = 0;
        for ($index = 0; $index < $this->reviews; ++$index) {
            if ($matches($index, $this->reviewProduct($index))) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * @param callable(int $mediaIndex): bool $matches
     */
    public function countMedia(callable $matches): int
    {
        return self::countIndices(total: $this->media, matches: $matches);
    }

    /**
     * @param callable(int $blobIndex): bool $matches
     */
    public function countSyntheticBlobs(callable $matches): int
    {
        return self::countIndices(total: $this->syntheticBlobs, matches: $matches);
    }

    /**
     * @param callable(int $categoryIndex): bool $matches
     */
    public function countCategories(callable $matches): int
    {
        return self::countIndices(total: $this->categories, matches: $matches);
    }

    /**
     * @param callable(int): bool $matches
     */
    private static function countIndices(int $total, callable $matches): int
    {
        $count = 0;
        for ($index = 0; $index < $total; ++$index) {
            if ($matches($index)) {
                ++$count;
            }
        }

        return $count;
    }
}
