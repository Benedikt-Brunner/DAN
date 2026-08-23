<?php

declare(strict_types=1);

namespace Dan\Harness\Protocol;

use Dan\Lib\Protocol\Tier;
use InvalidArgumentException;

final class ProtocolResolver
{
    /**
     * @param list<string> $databaseSpecs e.g. ["mysql:8.0", "mariadb:11.4"]
     * @param list<string> $tiers e.g. ["S", "M"]
     */
    public function resolve(
        array $databaseSpecs,
        array $tiers,
        int $warmupIterations,
        int $measuredIterations,
        int $blocks,
        ?string $scenarioFilter,
    ): Protocol {
        if ($databaseSpecs === []) {
            throw new InvalidArgumentException('At least one --db target is required (e.g. --db mysql:8.0).');
        }
        if ($tiers === []) {
            throw new InvalidArgumentException('At least one --tier is required (S, M or L).');
        }
        $resolvedTiers = [];
        foreach (array_values(array_unique($tiers)) as $tier) {
            $resolvedTiers[] = Tier::tryFrom($tier) ?? throw new InvalidArgumentException(sprintf('Unknown tier "%s", expected one of: %s.', $tier, implode(', ', array_map(fn (Tier $t) => $t->value, Tier::cases()))));
        }
        if ($measuredIterations < 1) {
            throw new InvalidArgumentException('--iterations must be at least 1.');
        }
        if ($warmupIterations < 0) {
            throw new InvalidArgumentException('--warmup must be zero or more.');
        }
        if ($blocks < 1) {
            throw new InvalidArgumentException('--blocks must be at least 1.');
        }
        if ($blocks > $measuredIterations) {
            throw new InvalidArgumentException('--blocks cannot exceed --iterations.');
        }

        return new Protocol(
            databases: array_map(DatabaseTarget::fromString(...), array_values($databaseSpecs)),
            tiers: $resolvedTiers,
            warmupIterations: $warmupIterations,
            measuredIterations: $measuredIterations,
            blocks: $blocks,
            scenarioFilter: $scenarioFilter,
        );
    }
}
