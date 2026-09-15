<?php

declare(strict_types=1);

namespace Dan\Probe\Scenario\Corpus;

use Dan\Probe\Scenario\Scenario;
use Dan\Probe\Seeding\Dataset\TierSpec;
use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\RangeFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;

/**
 * Query-shape dimension: flat. The baseline every association shape is
 * measured against.
 */
final class ProductStockRangeScenario implements Scenario
{
    public function name(): string
    {
        return 'product.stock-range';
    }

    public function describe(): string
    {
        return 'Flat read: a single-table range predicate on an indexed integer column with a sort and no associations';
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
        $criteria->addFilter(new RangeFilter('stock', [RangeFilter::GTE => 990]));
        $criteria->addSorting(new FieldSorting('stock', FieldSorting::DESCENDING));
        $criteria->setLimit(50);

        return $criteria;
    }

    public function expectedTotal(TierSpec $spec): int
    {
        return $spec->countProducts(fn (int $index, bool $variant): bool => !$variant && $spec->productStock($index) >= 990);
    }
}
