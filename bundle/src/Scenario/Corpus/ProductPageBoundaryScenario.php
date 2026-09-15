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
 * Cardinality dimension: exactly one page at tier S (24 of 1 000 parents),
 * many pages at M and L - the same criteria crossing the page boundary.
 */
final class ProductPageBoundaryScenario implements Scenario
{
    public function name(): string
    {
        return 'product.page-boundary';
    }

    public function describe(): string
    {
        return 'Indexed range predicate whose match count equals the page size at tier S, so the reader loads exactly one full page';
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
        $criteria->addFilter(new RangeFilter('stock', [RangeFilter::LT => 24]));
        $criteria->addSorting(new FieldSorting('productNumber', FieldSorting::ASCENDING));
        $criteria->setLimit(24);
        $criteria->setTotalCountMode(Criteria::TOTAL_COUNT_MODE_EXACT);

        return $criteria;
    }

    public function expectedTotal(TierSpec $spec): int
    {
        return $spec->countProducts(fn (int $index, bool $variant): bool => !$variant && $spec->productStock($index) < 24);
    }
}
