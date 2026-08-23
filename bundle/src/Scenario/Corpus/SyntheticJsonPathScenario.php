<?php

declare(strict_types=1);

namespace Dan\Probe\Scenario\Corpus;

use Dan\Probe\Scenario\Scenario;
use Dan\Probe\Synthetic\SyntheticBlobDefinition;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\RangeFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;

/**
 * Exercises typed JSON-path filtering and sorting. The DAL resolves the
 * nested payload fields into engine-sensitive JSON_EXTRACT expressions.
 */
final class SyntheticJsonPathScenario implements Scenario
{
    public function name(): string
    {
        return 'synthetic.json-path';
    }

    public function entity(): string
    {
        return SyntheticBlobDefinition::ENTITY_NAME;
    }

    public function criteria(Context $context): Criteria
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('payload.segment', 'segment-07'));
        $criteria->addFilter(new EqualsFilter('payload.active', true));
        $criteria->addFilter(new RangeFilter('payload.score', [RangeFilter::GTE => 900]));
        $criteria->addSorting(new FieldSorting('payload.score', FieldSorting::DESCENDING));
        $criteria->addSorting(new FieldSorting('rank', FieldSorting::ASCENDING));
        $criteria->setLimit(50);
        $criteria->setTotalCountMode(Criteria::TOTAL_COUNT_MODE_EXACT);

        return $criteria;
    }
}
