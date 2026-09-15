<?php

declare(strict_types=1);

namespace Dan\Probe\Scenario\Corpus;

use Dan\Probe\Scenario\Scenario;
use Dan\Probe\Seeding\Dataset\TierSpec;
use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;

/**
 * Predicate dimension: intentionally unindexed, the counterpart of every
 * indexed lookup in the corpus.
 */
final class ProductUnindexedEanScenario implements Scenario
{
    public function name(): string
    {
        return 'product.unindexed-ean';
    }

    public function describe(): string
    {
        return 'Equality on a column without an index (ean): the predicate forces a scan the optimizer cannot avoid';
    }

    public function entity(): string
    {
        return ProductDefinition::ENTITY_NAME;
    }

    public function considerInheritance(): bool
    {
        return false;
    }

    public function criteria(Context $context): Criteria
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('ean', 'DANEAN00000042'));
        $criteria->setLimit(24);

        return $criteria;
    }

    public function expectedTotal(TierSpec $spec): int
    {
        return $spec->countProducts(fn (int $index, bool $variant): bool => !$variant && $index === 42);
    }
}
