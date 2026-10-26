<?php

declare(strict_types=1);

namespace Dan\Probe\Scenario;

use Dan\Probe\Seeding\Dataset\TierSpec;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;

/**
 * One corpus entry. Scenarios must only use the public Criteria API and must
 * be deterministic against a seeded dataset: same tier, same Criteria, same
 * result - the recorder flags cells whose statement sequence varies between
 * iterations as divergent, and every scenario states the DAL behaviour it
 * represents and the exact total it must return per tier, derived from the
 * dataset rules in TierSpec. A scenario describes a general DAL shape (see
 * CORPUS.md), never the optimization it was written to prove.
 */
interface Scenario
{
    /**
     * Stable, unique corpus name, e.g. "product.keyword-listing". Renaming a
     * scenario breaks profile-diff continuity - treat names as append-only.
     */
    public function name(): string;

    /**
     * The DAL behaviour this scenario represents, in one sentence.
     */
    public function describe(): string;

    public function entity(): string;

    /**
     * Whether the search runs with inheritance considered: variant children
     * then resolve inherited fields (name, categories, price) through their
     * parent, and the DAL emits the parent joins and COALESCE expressions to
     * do so. Storefront contexts have it on; the default context has it off.
     */
    public function considerInheritance(): bool;

    public function criteria(Context $context): Criteria;

    /**
     * The exact total the criteria must report against a dataset of the
     * given tier - the correctness expectation the seeded data guarantees.
     */
    public function expectedTotal(TierSpec $spec): int;
}
