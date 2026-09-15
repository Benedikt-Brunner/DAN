<?php

declare(strict_types=1);

namespace Dan\Probe\Execution\Result;

use Dan\Lib\Protocol\StatementDivergence;

/**
 * The immutable result for one statement position across measured iterations.
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
    ) {}

    /**
     * @return array{
     *     index: int,
     *     sql: string,
     *     durationsNsSamples: list<int>,
     *     observed: int,
     *     divergence: string
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
        ];
    }
}
