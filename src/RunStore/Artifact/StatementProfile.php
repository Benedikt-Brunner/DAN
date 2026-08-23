<?php

declare(strict_types=1);

namespace Dan\Harness\RunStore\Artifact;

use Dan\Harness\Measurement\Result\SampleCollection;
use RuntimeException;

/**
 * Profile of one statement position in a scenario's statement sequence: the
 * recorded SQL plus duration samples, accumulated across measurement blocks.
 * Durations are integer nanoseconds end-to-end - exact by construction;
 * conversion to milliseconds happens only at presentation.
 *
 * @phpstan-type StatementProfilePayload array{
 *     index: int,
 *     sql: string,
 *     durationsNsSamples: list<int>,
 *     divergent: bool
 * }
 */
final class StatementProfile
{
    public function __construct(
        public readonly int $index,
        public readonly string $sql,
        public readonly SampleCollection $durationSamples,
        public readonly bool $divergent,
    ) {}

    /**
     * Appends the other block's samples. A statement whose SQL differs
     * between blocks means the scenario is not deterministic against this
     * dataset - the first SQL is kept and the statement is flagged so the
     * diff report never silently averages apples and oranges.
     */
    public function merge(self $other): self
    {
        return new self(
            index: $this->index,
            sql: $this->sql,
            durationSamples: $this->durationSamples->merge($other->durationSamples),
            divergent: $this->divergent || $other->divergent || $this->sql !== $other->sql,
        );
    }

    /** @return StatementProfilePayload */
    public function toArray(): array
    {
        return [
            'index' => $this->index,
            'sql' => $this->sql,
            'durationsNsSamples' => $this->durationSamples->toNsArray(),
            'divergent' => $this->divergent,
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
            divergent: $payload['divergent'],
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
        $divergent = $payload['divergent'] ?? null;
        if (!is_int($index) || !is_string($sql) || !is_array($durationSamples) || !array_is_list($durationSamples) || !is_bool($divergent)) {
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
            'divergent' => $divergent,
        ]);
    }
}
