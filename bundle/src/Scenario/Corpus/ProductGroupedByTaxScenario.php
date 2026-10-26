<?php

declare(strict_types=1);

namespace Dan\Probe\Scenario\Corpus;

use Dan\Probe\Scenario\Scenario;
use Dan\Probe\Seeding\Dataset\TierSpec;
use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Grouping\FieldGrouping;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;

/**
 * Grouping dimension: the searcher emits GROUP BY and counts groups, not
 * rows.
 */
final class ProductGroupedByTaxScenario implements Scenario
{
    public function name(): string
    {
        return 'product.grouped-by-tax';
    }

    public function describe(): string
    {
        return 'GROUP BY through a field grouping: one representative product per tax';
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
        $criteria->addGroupField(new FieldGrouping('taxId'));
        $criteria->addSorting(new FieldSorting('productNumber', FieldSorting::ASCENDING));
        $criteria->setLimit(50);
        $criteria->setTotalCountMode(Criteria::TOTAL_COUNT_MODE_EXACT);

        return $criteria;
    }

    public function expectedTotal(TierSpec $spec): int
    {
        // All DAN products share one tax, so grouping collapses them to one.
        return 1;
    }
}
