<?php

declare(strict_types=1);

namespace Dan\Probe\Scenario\Corpus;

use Dan\Probe\Scenario\Scenario;
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

    public function entity(): string
    {
        return ProductDefinition::ENTITY_NAME;
    }

    public function criteria(Context $context): Criteria
    {
        $criteria = new Criteria();
        $criteria->addFilter(new RangeFilter('stock', [RangeFilter::GTE => 10]));
        $criteria->addAssociation('categories');
        $criteria->addAssociation('tax');
        $criteria->addAssociation('prices');
        $criteria->addSorting(new FieldSorting('stock', FieldSorting::DESCENDING));
        $criteria->setLimit(50);

        return $criteria;
    }
}
