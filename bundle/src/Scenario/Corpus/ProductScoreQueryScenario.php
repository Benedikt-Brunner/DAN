<?php

declare(strict_types=1);

namespace Dan\Probe\Scenario\Corpus;

use Dan\Probe\Scenario\Scenario;
use Dan\Probe\Seeding\Dataset\TierSpec;
use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\ContainsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Query\ScoreQuery;

/**
 * Scoring dimension: the DAL computes a score expression and sorts by it.
 */
final class ProductScoreQueryScenario implements Scenario
{
    public function name(): string
    {
        return 'product.score-query';
    }

    public function describe(): string
    {
        return 'Score queries: matches ranked by a computed _score, with the query doubling as the filter';
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
        $criteria->addQuery(new ScoreQuery(new ContainsFilter('name', 'Product 0000'), 100.0));
        $criteria->setLimit(50);
        $criteria->setTotalCountMode(Criteria::TOTAL_COUNT_MODE_EXACT);

        return $criteria;
    }

    public function expectedTotal(TierSpec $spec): int
    {
        // "DAN Product 0000xxxx": indexes below 10 000. Inheritance is off, so
        // nameless variant children never match.
        return $spec->countProducts(fn (int $index, bool $variant): bool => !$variant && $index < 10_000);
    }
}
