<?php

declare(strict_types=1);

namespace Dan\Probe\Scenario\Corpus;

use Dan\Probe\Scenario\Scenario;
use Dan\Probe\Seeding\Dataset\TierSpec;
use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Bucket\TermsAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\RangeFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;

/**
 * Post-filter and aggregation dimension: the aggregation query sees the
 * unfiltered set, the result the filtered one.
 */
final class ProductPostFilterScenario implements Scenario
{
    public function name(): string
    {
        return 'product.post-filter';
    }

    public function describe(): string
    {
        return 'Post-filter applied after aggregation scope: the filter reaches the searcher but not the aggregations';
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
        $criteria->addPostFilter(new RangeFilter('stock', [RangeFilter::GTE => 500]));
        $criteria->addAggregation(new TermsAggregation('stock-terms', 'stock'));
        $criteria->addSorting(new FieldSorting('productNumber', FieldSorting::ASCENDING));
        $criteria->setLimit(50);
        $criteria->setTotalCountMode(Criteria::TOTAL_COUNT_MODE_EXACT);

        return $criteria;
    }

    public function expectedTotal(TierSpec $spec): int
    {
        return $spec->countProducts(fn (int $index, bool $variant): bool => !$variant && $spec->productStock($index) >= 500);
    }
}
