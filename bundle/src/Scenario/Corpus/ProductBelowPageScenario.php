<?php

declare(strict_types=1);

namespace Dan\Probe\Scenario\Corpus;

use Dan\Probe\Scenario\Scenario;
use Dan\Probe\Seeding\Dataset\TierSpec;
use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\RangeFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;

/**
 * Cardinality dimension: a result below the page size (two products per
 * thousand; their variant parents never fall into that stock band).
 */
final class ProductBelowPageScenario implements Scenario
{
    public function name(): string
    {
        return 'product.below-page';
    }

    public function describe(): string
    {
        return 'Indexed range predicate selecting fewer rows than one page: searcher and reader both touch a handful of rows';
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
        $criteria->addFilter(new RangeFilter('stock', [RangeFilter::GTE => 998]));
        $criteria->addSorting(new FieldSorting('productNumber', FieldSorting::ASCENDING));
        $criteria->setLimit(24);
        $criteria->setTotalCountMode(Criteria::TOTAL_COUNT_MODE_EXACT);

        return $criteria;
    }

    public function expectedTotal(TierSpec $spec): int
    {
        return $spec->countProducts(fn (int $index, bool $variant): bool => $spec->productStock($index) >= 998);
    }
}
