<?php

declare(strict_types=1);

namespace Dan\Probe\Scenario\Corpus;

use Dan\Probe\Scenario\Scenario;
use Dan\Probe\Seeding\Dataset\TierSpec;
use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;

/**
 * Cardinality dimension: an empty result. The searcher finds nothing and
 * the DAL must not issue a reader query for zero ids.
 */
final class ProductEmptyResultScenario implements Scenario
{
    public function name(): string
    {
        return 'product.empty-result';
    }

    public function describe(): string
    {
        return 'Indexed equality on a unique column that matches nothing: the empty-result path with no reader query to follow';
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
        $criteria->addFilter(new EqualsFilter('productNumber', 'DAN-NONE'));
        $criteria->setLimit(24);

        return $criteria;
    }

    public function expectedTotal(TierSpec $spec): int
    {
        return 0;
    }
}
