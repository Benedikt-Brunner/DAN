<?php

declare(strict_types=1);

namespace Dan\Probe\Scenario\Corpus;

use Dan\Probe\Scenario\Scenario;
use Dan\Probe\Seeding\Dataset\TierSpec;
use Shopware\Core\Content\Product\Aggregate\ProductReview\ProductReviewDefinition;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\ContainsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;

/**
 * Query-shape dimension: deep association chain on a second entity
 * (reviews), reaching a translated field two joins away.
 */
final class ReviewDeepAssociationScenario implements Scenario
{
    public function name(): string
    {
        return 'review.deep-association';
    }

    public function describe(): string
    {
        return 'Filter three associations deep (review -> product -> categories -> translation) and load the product';
    }

    public function entity(): string
    {
        return ProductReviewDefinition::ENTITY_NAME;
    }

    public function considerInheritance(): bool
    {
        return false;
    }

    public function criteria(Context $context): Criteria
    {
        $criteria = new Criteria();
        $criteria->addFilter(new ContainsFilter('product.categories.name', 'DAN Category 000'));
        $criteria->addAssociation('product');
        $criteria->addSorting(new FieldSorting('title', FieldSorting::ASCENDING));
        $criteria->setLimit(50);
        $criteria->setTotalCountMode(Criteria::TOTAL_COUNT_MODE_EXACT);

        return $criteria;
    }

    public function expectedTotal(TierSpec $spec): int
    {
        // Review r reviews product 2r, whose category index is 2r mod categories;
        // "DAN Category 000x" are the first ten categories.
        return $spec->countReviews(fn (int $review, int $product): bool => $spec->productCategory($product) < 10);
    }
}
