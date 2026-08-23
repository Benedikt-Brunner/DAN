<?php

declare(strict_types=1);

namespace Dan\Probe\Execution\Measurement;

use Dan\Probe\Execution\Result\StatementResult;
use Dan\Probe\Recorder\RecordedStatement;

/**
 * Accumulates samples for one statement position across measured iterations.
 */
final class StatementMeasurementAccumulator
{
    /** @var list<int> */
    private array $durationSamplesNs = [];

    private bool $divergent = false;

    public function __construct(
        private readonly int $index,
        private readonly string $sql,
    ) {}

    public function record(RecordedStatement $statement): void
    {
        if ($statement->sql !== $this->sql) {
            $this->divergent = true;
        }

        $this->durationSamplesNs[] = $statement->duration->toNsInt();
    }

    public function result(): StatementResult
    {
        return new StatementResult(
            index: $this->index,
            sql: $this->sql,
            durationSamplesNs: $this->durationSamplesNs,
            divergent: $this->divergent,
        );
    }
}
