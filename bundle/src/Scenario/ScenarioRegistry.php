<?php

declare(strict_types=1);

namespace Dan\Probe\Scenario;

use Traversable;

final class ScenarioRegistry
{
    /** @var list<Scenario> */
    private readonly array $scenarios;

    /**
     * @param iterable<Scenario> $scenarios
     */
    public function __construct(iterable $scenarios)
    {
        $this->scenarios = $scenarios instanceof Traversable
            ? iterator_to_array($scenarios, false)
            : array_values($scenarios);
    }

    /**
     * @return list<Scenario>
     */
    public function matching(?string $filter): array
    {
        return array_values(array_filter(
            $this->scenarios,
            fn (Scenario $scenario): bool => $filter === null || str_contains($scenario->name(), $filter),
        ));
    }
}
