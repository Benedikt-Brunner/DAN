<?php

declare(strict_types=1);

namespace Dan\Probe\Execution\Measurement;

use Dan\Lib\Protocol\ResultSet;
use LogicException;

/**
 * Watches what every measured iteration returned. The first result set is
 * the one recorded; every later iteration must return exactly the same, or
 * the scenario is not deterministic against this dataset and the recorded
 * result is marked inconsistent instead of silently describing one of many.
 */
final class ResultSetAccumulator
{
    private ?ResultSet $first = null;

    private bool $consistent = true;

    public function observe(ResultSet $resultSet): void
    {
        if ($this->first === null) {
            $this->first = $resultSet;

            return;
        }
        if (!$this->first->equals($resultSet)) {
            $this->consistent = false;
        }
    }

    public function resultSet(): ResultSet
    {
        return $this->first ?? throw new LogicException('No iteration has been observed yet.');
    }

    public function consistent(): bool
    {
        return $this->consistent;
    }
}
