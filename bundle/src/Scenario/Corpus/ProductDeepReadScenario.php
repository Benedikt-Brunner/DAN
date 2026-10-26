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
 * A wide read with several associations of different cardinalities - the
 * kind of query where DAL changes to join/fetch strategies show up first.
 */
final class ProductDeepReadScenario implements Scenario
{
    public function name(): string
    {
        return 'product.deep-read';
    }

    public function describe(): string
    {
        return 'Wide read loading several associations of different cardinality (to-one tax, to-many prices, many-to-many categories) behind a range filter and a sort, without exact total counting';
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
        $criteria->addFilter(new RangeFilter('stock', [RangeFilter::GTE => 10]));
        $criteria->addAssociation('categories');
        $criteria->addAssociation('tax');
        $criteria->addAssociation('prices');
        $criteria->addSorting(new FieldSorting('stock', FieldSorting::DESCENDING));
        // Variants share their parent's stock: the unique id makes the page deterministic.
        $criteria->addSorting(new FieldSorting('id', FieldSorting::ASCENDING));
        $criteria->setLimit(50);

        return $criteria;
    }

    public function expectedTotal(TierSpec $spec): int
    {
        // No exact total count: the DAL reports the page it loaded.
        return min(50, $spec->countProducts(fn (int $index, bool $variant): bool => !$variant && $spec->productStock($index) >= 10));
    }
}
