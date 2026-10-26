<?php

declare(strict_types=1);

namespace Dan\Probe\Scenario\Corpus;

use Dan\Probe\Scenario\Scenario;
use Dan\Probe\Seeding\Dataset\TierSpec;
use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\ContainsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;

/**
 * A storefront-listing-shaped read: translated-field filtering, a to-many
 * association, sorting and exact total counting on one of the widest core
 * entities. Variant children match through name inheritance.
 */
final class ProductKeywordListingScenario implements Scenario
{
    public function name(): string
    {
        return 'product.keyword-listing';
    }

    public function describe(): string
    {
        return 'Storefront listing: substring filter on a translated field, a to-many association, sorting and an exact total over a wide entity - variants included through inheritance';
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
        $criteria->addFilter(new ContainsFilter('name', 'DAN Product 000'));
        $criteria->addAssociation('categories');
        $criteria->addSorting(new FieldSorting('productNumber', FieldSorting::ASCENDING));
        $criteria->setLimit(24);
        $criteria->setTotalCountMode(Criteria::TOTAL_COUNT_MODE_EXACT);

        return $criteria;
    }

    public function expectedTotal(TierSpec $spec): int
    {
        // Names are "DAN Product %08d": only indexes below 100 000 read
        // "DAN Product 000..."; with inheritance on, variants match through
        // their parent's name.
        return $spec->countProducts(fn (int $index, bool $variant): bool => $index < 100_000);
    }
}
