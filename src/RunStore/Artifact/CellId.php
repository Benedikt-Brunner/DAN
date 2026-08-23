<?php

declare(strict_types=1);

namespace Dan\Harness\RunStore\Artifact;

use Dan\Harness\Protocol\DatabaseTarget;
use Dan\Lib\Protocol\ScenarioName;
use Dan\Lib\Protocol\Tier;

/**
 * Identifies one cell of the measurement grid: scenario x dataset tier x
 * database target, for a single DAL implementation (the run).
 */
final class CellId
{
    public function __construct(
        public readonly ScenarioName $scenario,
        public readonly Tier $tier,
        public readonly DatabaseTarget $database,
    ) {}

    public function fileName(): string
    {
        return sprintf(
            '%s--%s--%s.json',
            preg_replace('/[^A-Za-z0-9._-]+/', '_', $this->scenario->toString()),
            $this->tier->value,
            $this->database->id(),
        );
    }
}
