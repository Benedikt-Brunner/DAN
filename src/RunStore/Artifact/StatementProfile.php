<?php

declare(strict_types=1);

namespace Dan\Harness\RunStore\Artifact;

use Dan\Harness\Measurement\Result\SampleCollection;
use Dan\Harness\Plan\QueryPlan;
use Dan\Lib\Protocol\StatementDivergence;
use RuntimeException;

/**
 * Profile of one statement position in a scenario's statement sequence: the
 * recorded SQL, the duration samples, how many iterations actually produced
 * a statement at this position, and how the position diverged (SQL text
 * varying between iterations, the position missing from some of them, or
 * both). Durations are integer nanoseconds end-to-end - exact by
 * construction; conversion to milliseconds happens only at presentation.
 *
 * @phpstan-import-type QueryPlanPayload from QueryPlan
 *
 * @phpstan-type StatementProfilePayload array{
 *     index: int,
 *     sql: string,
 *     durationsNsSamples: list<int>,
 *     observed: int,
 *     divergence: string,
 *     plan: QueryPlanPayload|null
 * }
 */
final class StatementProfile
{
    public function __construct(
        public readonly int $index,
        public readonly string $sql,
        public readonly SampleCollection $durationSamples,
        public readonly int $observed,
        public readonly StatementDivergence $divergence,
        public readonly ?QueryPlan $plan,
    ) {}

    /**
     * Appends the other block's samples and observations. A statement whose
     * SQL differs between blocks means the scenario is not deterministic
     * against this dataset - the first SQL is kept and the position is marked
     * text-divergent so the diff report never silently averages apples and
     * oranges. Presence is judged again by the caller against the pooled
     * iteration count (see withPresenceAgainst()).
     */
    public function merge(self $other): self
    {
        return new self(
            index: $this->index,
            sql: $this->sql,
            durationSamples: $this->durationSamples->merge($other->durationSamples),
            observed: $this->observed + $other->observed,
            divergence: $this->divergence->merge($other->divergence)->merge(
                StatementDivergence::fromFlags(textDiffers: $this->sql !== $other->sql, intermittent: false),
            ),
            // Plans are captured once per cell; whichever block has one wins.
            plan: $this->plan ?? $other->plan,
        );
    }

    /**
     * A position observed in fewer than the given iterations is intermittent:
     * its timings describe a subset of the measurement and must say so.
     */
    public function withPresenceAgainst(int $iterations): self
    {
        return new self(
            index: $this->index,
            sql: $this->sql,
            durationSamples: $this->durationSamples,
            observed: $this->observed,
            divergence: $this->divergence->merge(
                StatementDivergence::fromFlags(textDiffers: false, intermittent: $this->observed < $iterations),
            ),
            plan: $this->plan,
        );
    }

    /** @return StatementProfilePayload */
    public function toArray(): array
    {
        return [
            'index' => $this->index,
            'sql' => $this->sql,
            'durationsNsSamples' => $this->durationSamples->toNsArray(),
            'observed' => $this->observed,
            'divergence' => $this->divergence->value,
            'plan' => $this->plan?->toArray(),
        ];
    }

    /**
     * @param StatementProfilePayload $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            index: $payload['index'],
            sql: $payload['sql'],
            durationSamples: SampleCollection::fromArray($payload['durationsNsSamples']),
            observed: $payload['observed'],
            divergence: StatementDivergence::tryFrom($payload['divergence']) ?? throw new RuntimeException(sprintf('Malformed statement profile: unknown divergence "%s".', $payload['divergence'])),
            plan: $payload['plan'] === null ? null : QueryPlan::fromDecodedArray($payload['plan']),
        );
    }

    /**
     * @param array<mixed> $payload
     */
    public static function fromDecodedArray(array $payload): self
    {
        $index = $payload['index'] ?? null;
        $sql = $payload['sql'] ?? null;
        $durationSamples = $payload['durationsNsSamples'] ?? null;
        $observed = $payload['observed'] ?? null;
        $divergence = $payload['divergence'] ?? null;
        $plan = $payload['plan'] ?? null;
        if (!is_int($index) || !is_string($sql) || !is_array($durationSamples) || !array_is_list($durationSamples) || !is_int($observed) || !is_string($divergence) || ($plan !== null && !is_array($plan))) {
            throw new RuntimeException('Malformed statement profile payload.');
        }
        foreach ($durationSamples as $duration) {
            if (!is_int($duration)) {
                throw new RuntimeException('Malformed statement profile: duration samples must be a list of integers.');
            }
        }

        return self::fromArray([
            'index' => $index,
            'sql' => $sql,
            'durationsNsSamples' => $durationSamples,
            'observed' => $observed,
            'divergence' => $divergence,
            'plan' => $plan === null ? null : QueryPlan::fromDecodedArray($plan)->toArray(),
        ]);
    }
}
