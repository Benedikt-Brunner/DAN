<?php

declare(strict_types=1);

namespace Dan\Probe\Scenario;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;

/**
 * One corpus entry. Scenarios must only use the public Criteria API and must
 * be deterministic against a seeded dataset: same tier, same Criteria, same
 * result - the recorder flags cells whose statement sequence varies between
 * iterations as divergent.
 */
interface Scenario
{
    /**
     * Stable, unique corpus name, e.g. "product.keyword-listing". Renaming a
     * scenario breaks profile-diff continuity - treat names as append-only.
     */
    public function name(): string;

    public function entity(): string;

    public function criteria(Context $context): Criteria;
}
