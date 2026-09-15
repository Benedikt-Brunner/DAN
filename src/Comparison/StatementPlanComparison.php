<?php

declare(strict_types=1);

namespace Dan\Harness\Comparison;

use Dan\Harness\Plan\PlanFacts;
use Dan\Harness\Plan\QueryPlan;
use Dan\Harness\Protocol\Engine;
use Dan\Lib\Protocol\PlanCapture;

/**
 * The plans behind one changed statement, baseline against candidate. Both
 * runs of a cell hit the same engine, so their plan facts are comparable;
 * a side whose plan was not captured says so instead of pretending.
 */
final class StatementPlanComparison
{
    public readonly ?PlanFacts $baselineFacts;
    public readonly ?PlanFacts $candidateFacts;

    public function __construct(
        public readonly AlignedStatement $statement,
        Engine $engine,
        public readonly ?QueryPlan $baseline,
        public readonly ?QueryPlan $candidate,
    ) {
        $this->baselineFacts = self::facts(engine: $engine, plan: $baseline);
        $this->candidateFacts = self::facts(engine: $engine, plan: $candidate);
    }

    /**
     * @return list<string> what changed between the two plans; empty when
     *                      they agree or when either side has no plan
     */
    public function materialChanges(): array
    {
        if ($this->baselineFacts === null || $this->candidateFacts === null) {
            return [];
        }

        return $this->baselineFacts->materialChangesTo($this->candidateFacts);
    }

    public function describeBaseline(): string
    {
        return self::describe(plan: $this->baseline, facts: $this->baselineFacts);
    }

    public function describeCandidate(): string
    {
        return self::describe(plan: $this->candidate, facts: $this->candidateFacts);
    }

    private static function facts(Engine $engine, ?QueryPlan $plan): ?PlanFacts
    {
        if ($plan === null || $plan->raw === null) {
            return null;
        }

        return PlanFacts::fromRaw(engine: $engine, raw: $plan->raw);
    }

    private static function describe(?QueryPlan $plan, ?PlanFacts $facts): string
    {
        if ($facts !== null) {
            return $facts->describe();
        }

        return match ($plan?->capture) {
            null => 'no statement',
            PlanCapture::Unsupported => 'not explainable',
            PlanCapture::Failed => 'plan capture failed',
            PlanCapture::Captured => 'plan not readable',
        };
    }
}
