<?php

declare(strict_types=1);

namespace Dan\Probe\Execution\Result;

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
        private bool $divergent,
    ) {}

    /**
     * @return array{
     *     index: int,
     *     sql: string,
     *     durationsNsSamples: list<int>,
     *     divergent: bool
     * }
     */
    public function toArray(): array
    {
        return [
            'index' => $this->index,
            'sql' => $this->sql,
            'durationsNsSamples' => $this->durationSamplesNs,
            'divergent' => $this->divergent,
        ];
    }
}
