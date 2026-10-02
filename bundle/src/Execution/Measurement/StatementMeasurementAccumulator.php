<?php

declare(strict_types=1);

namespace Dan\Probe\Execution\Measurement;

use Dan\Lib\Protocol\StatementDivergence;
use Dan\Probe\Execution\Result\StatementResult;
use Dan\Probe\Recorder\RecordedStatement;

/**
 * Accumulates samples for one statement position across measured iterations,
 * counting the iterations that actually produced a statement at this
 * position so an intermittent statement can never pose as a stable one.
 */
final class StatementMeasurementAccumulator
{
    /** @var list<int> */
    private array $durationSamplesNs = [];

    private int $observed = 0;

    private bool $textDiffers = false;

    public function __construct(
        private readonly int $index,
        private readonly string $sql,
    ) {}

    public function record(RecordedStatement $statement): void
    {
        if ($statement->sql !== $this->sql) {
            $this->textDiffers = true;
        }

        ++$this->observed;
        $this->durationSamplesNs[] = $statement->duration->toNsInt();
    }

    /**
     * @param int $iterations the measured iterations of the scenario; a position
     *                        observed in fewer of them is intermittent
     */
    public function result(int $iterations): StatementResult
    {
        return new StatementResult(
            index: $this->index,
            sql: $this->sql,
            durationSamplesNs: $this->durationSamplesNs,
            observed: $this->observed,
            divergence: StatementDivergence::fromFlags(textDiffers: $this->textDiffers, intermittent: $this->observed < $iterations),
        );
    }
}
