<?php

declare(strict_types=1);

namespace Dan\Probe\Execution\Result;

use Dan\Lib\Protocol\StatementDivergence;

/**
 * The immutable result for one statement position across measured iterations.
 * The plan is null when this invocation was not asked to capture plans.
 */
final readonly class StatementResult
{
    /** @param list<int> $durationSamplesNs */
    public function __construct(
        private int $index,
        private string $sql,
        private array $durationSamplesNs,
        private int $observed,
        private StatementDivergence $divergence,
        private ?CapturedPlan $plan,
    ) {}

    /**
     * @return array{
     *     index: int,
     *     sql: string,
     *     durationsNsSamples: list<int>,
     *     observed: int,
     *     divergence: string,
     *     plan: array{capture: string, raw: array<mixed>|null}|null
     * }
     */
    public function toArray(): array
    {
        return [
            'index' => $this->index,
            'sql' => $this->sql,
            'durationsNsSamples' => $this->durationSamplesNs,
            'observed' => $this->observed,
            'divergence' => $this->divergence->value,
            'plan' => $this->plan?->toArray(),
        ];
    }
}
