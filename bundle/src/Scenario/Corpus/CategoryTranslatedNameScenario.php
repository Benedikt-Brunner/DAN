<?php

declare(strict_types=1);

namespace Dan\Probe\Scenario\Corpus;

use Dan\Probe\Scenario\Scenario;
use Dan\Probe\Seeding\Dataset\TierSpec;
use Shopware\Core\Content\Category\CategoryDefinition;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\ContainsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;

/**
 * Translation dimension on a second core entity: the translation join
 * with the system-language fallback chain.
 */
final class CategoryTranslatedNameScenario implements Scenario
{
    public function name(): string
    {
        return 'category.translated-name';
    }

    public function describe(): string
    {
        return 'Substring filter and sort on a translated field of a translated entity with language fallback';
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
        $criteria->addFilter(new ContainsFilter('name', 'DAN Category 000'));
        $criteria->addSorting(new FieldSorting('name', FieldSorting::ASCENDING));
        $criteria->setLimit(50);
        $criteria->setTotalCountMode(Criteria::TOTAL_COUNT_MODE_EXACT);

        return $criteria;
    }

    public function expectedTotal(TierSpec $spec): int
    {
        // "DAN Category %04d": indexes below 10.
        return $spec->countCategories(fn (int $index): bool => $index < 10);
    }
}
