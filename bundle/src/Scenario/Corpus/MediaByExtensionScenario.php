<?php

declare(strict_types=1);

namespace Dan\Probe\Scenario\Corpus;

use Dan\Probe\Scenario\Scenario;
use Dan\Probe\Seeding\Dataset\TierSpec;
use Shopware\Core\Content\Media\MediaDefinition;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;

/**
 * Entity dimension: media. Its reader hydrates runtime fields (url, hasFile)
 * and thumbnails on top of the plain columns.
 */
final class MediaByExtensionScenario implements Scenario
{
    public function name(): string
    {
        return 'media.by-extension';
    }

    public function describe(): string
    {
        return 'Equality filter and sort on media, an entity with translations and runtime fields that the reader must compute';
    }

    public function entity(): string
    {
        return MediaDefinition::ENTITY_NAME;
    }

    public function considerInheritance(): bool
    {
        return false;
    }

    public function criteria(Context $context): Criteria
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('fileExtension', 'png'));
        $criteria->addSorting(new FieldSorting('fileName', FieldSorting::ASCENDING));
        $criteria->setLimit(50);
        $criteria->setTotalCountMode(Criteria::TOTAL_COUNT_MODE_EXACT);

        return $criteria;
    }

    public function expectedTotal(TierSpec $spec): int
    {
        return $spec->countMedia(fn (int $index): bool => TierSpec::mediaExtension($index) === 'png');
    }
}
