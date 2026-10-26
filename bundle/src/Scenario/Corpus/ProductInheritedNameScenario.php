<?php

declare(strict_types=1);

namespace Dan\Probe\Scenario\Corpus;

use Dan\Probe\Scenario\Scenario;
use Dan\Probe\Seeding\Dataset\TierSpec;
use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\ContainsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;

/**
 * Inheritance dimension: children have no name of their own, so the
 * filter can only match through the parent fallback.
 */
final class ProductInheritedNameScenario implements Scenario
{
    public function name(): string
    {
        return 'product.inherited-name';
    }

    public function describe(): string
    {
        return 'Variant children matched on an inherited translated field: the DAL resolves the value through the parent';
    }

    public function entity(): string
    {
        return ProductDefinition::ENTITY_NAME;
    }

    public function considerInheritance(): bool
    {
        return true;
    }

    public function criteria(Context $context): Criteria
    {
        $criteria = new Criteria();
        $criteria->addFilter(new NotFilter(NotFilter::CONNECTION_AND, [new EqualsFilter('parentId', null)]));
        $criteria->addFilter(new ContainsFilter('name', 'DAN Product'));
        $criteria->addSorting(new FieldSorting('productNumber', FieldSorting::ASCENDING));
        $criteria->setLimit(50);
        $criteria->setTotalCountMode(Criteria::TOTAL_COUNT_MODE_EXACT);

        return $criteria;
    }

    public function expectedTotal(TierSpec $spec): int
    {
        return $spec->countProducts(fn (int $index, bool $variant): bool => $variant);
    }
}
