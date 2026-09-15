<?php

declare(strict_types=1);

namespace Dan\Probe\Scenario\Corpus;

use Dan\Probe\Scenario\Scenario;
use Dan\Probe\Seeding\Dataset\DeterministicId;
use Dan\Probe\Seeding\Dataset\TierSpec;
use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;

/**
 * Query-shape dimension: many-to-many. The searcher joins the mapping
 * table; inherited categories bring the variants along.
 */
final class ProductInCategoryScenario implements Scenario
{
    public function name(): string
    {
        return 'product.in-category';
    }

    public function describe(): string
    {
        return 'Filter through a many-to-many mapping table (product_category) by the associated id';
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
        $criteria->addFilter(new EqualsFilter('categories.id', (string) DeterministicId::create('category:0')));
        $criteria->addSorting(new FieldSorting('productNumber', FieldSorting::ASCENDING));
        $criteria->setLimit(50);
        $criteria->setTotalCountMode(Criteria::TOTAL_COUNT_MODE_EXACT);

        return $criteria;
    }

    public function expectedTotal(TierSpec $spec): int
    {
        // Variants inherit their parent's categories.
        return $spec->countProducts(fn (int $index, bool $variant): bool => $spec->productCategory($index) === 0);
    }
}
