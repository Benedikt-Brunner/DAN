<?php

declare(strict_types=1);

namespace Dan\Probe\Scenario\Corpus;

use Dan\Probe\Scenario\Scenario;
use Dan\Probe\Seeding\Dataset\TierSpec;
use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;

/**
 * Query-shape dimension: to-one. Filtering and loading through a
 * many-to-one association.
 */
final class ProductTaxToOneScenario implements Scenario
{
    public function name(): string
    {
        return 'product.tax-to-one';
    }

    public function describe(): string
    {
        return 'Filter through a to-one association (tax rate) and load it: one join on both the searcher and the reader side';
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
        // Parents only: variants inherit most fields and would blur the count.
        $criteria->addFilter(new EqualsFilter('parentId', null));
        $criteria->addFilter(new EqualsFilter('tax.taxRate', 19.0));
        $criteria->addAssociation('tax');
        $criteria->addSorting(new FieldSorting('productNumber', FieldSorting::ASCENDING));
        $criteria->setLimit(50);
        $criteria->setTotalCountMode(Criteria::TOTAL_COUNT_MODE_EXACT);

        return $criteria;
    }

    public function expectedTotal(TierSpec $spec): int
    {
        // Every seeded product carries the DAN 19% tax.
        return $spec->countProducts(fn (int $index, bool $variant): bool => !$variant);
    }
}
