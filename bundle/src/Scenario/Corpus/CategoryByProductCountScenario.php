<?php

declare(strict_types=1);

namespace Dan\Probe\Scenario\Corpus;

use Dan\Probe\Scenario\Scenario;
use Dan\Probe\Seeding\Dataset\TierSpec;
use Shopware\Core\Content\Category\CategoryDefinition;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\ContainsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\CountSorting;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;

/**
 * Count-sorting dimension: ordering categories by how many products they
 * hold.
 */
final class CategoryByProductCountScenario implements Scenario
{
    public function name(): string
    {
        return 'category.by-product-count';
    }

    public function describe(): string
    {
        return 'Sorting by the count of a many-to-many association (CountSorting): GROUP BY plus COUNT in the sort';
    }

    public function entity(): string
    {
        return CategoryDefinition::ENTITY_NAME;
    }

    public function considerInheritance(): bool
    {
        return false;
    }

    public function criteria(Context $context): Criteria
    {
        $criteria = new Criteria();
        $criteria->addFilter(new ContainsFilter('name', 'DAN Category'));
        $criteria->addSorting(new CountSorting('products.id', CountSorting::DESCENDING));
        $criteria->addSorting(new FieldSorting('name', FieldSorting::ASCENDING));
        $criteria->setLimit(50);
        $criteria->setTotalCountMode(Criteria::TOTAL_COUNT_MODE_EXACT);

        return $criteria;
    }

    public function expectedTotal(TierSpec $spec): int
    {
        return $spec->categories;
    }
}
