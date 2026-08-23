<?php

declare(strict_types=1);

namespace Dan\Probe\Seeding\Dataset;

use Dan\Lib\Protocol\Tier;

/**
 * Row counts per dataset tier. Changing any number here alters seeder output,
 * so SnapshotCache::SEEDER_VERSION must be bumped at the same time.
 */
final class TierSpec
{
    public function __construct(
        public readonly Tier $tier,
        public readonly int $products,
        public readonly int $categories,
        public readonly int $syntheticBlobs,
    ) {}

    public static function forTier(Tier $tier): self
    {
        return match ($tier) {
            Tier::S => new self(tier: $tier, products: 1_000, categories: 50, syntheticBlobs: 1_000),
            Tier::M => new self(tier: $tier, products: 100_000, categories: 500, syntheticBlobs: 100_000),
            Tier::L => new self(tier: $tier, products: 1_000_000, categories: 2_000, syntheticBlobs: 1_000_000),
        };
    }
}
