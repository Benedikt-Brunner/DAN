<?php

declare(strict_types=1);

namespace Dan\Harness\Measurement\Result;

use Dan\Lib\Collections\Collection;
use Dan\Lib\Time\Duration;
use RuntimeException;

/**
 * @extends Collection<Sample>
 */
final readonly class SampleCollection extends Collection
{
    /**
     * @param array<int|float> $samples raw nanosecond durations
     */
    public static function fromArray(array $samples): self
    {
        return self::create(array_map(
            fn (int|float $sample): Sample => Sample::create(Duration::fromNs($sample)),
            array_values($samples),
        ));
    }

    /**
     * @param array<mixed> $payload
     */
    public static function fromDecodedArray(array $payload): self
    {
        if (!array_is_list($payload)) {
            throw new RuntimeException('Malformed samples payload: expected a list.');
        }
        foreach ($payload as $sample) {
            if (!is_int($sample)) {
                throw new RuntimeException('Malformed samples payload: every sample must be integer nanoseconds.');
            }
        }

        return self::fromArray($payload);
    }

    public function merge(self $other): self
    {
        return self::create([
            ...$this->getItems(),
            ...$other->getItems(),
        ]);
    }

    /**
     * @return list<int>
     */
    public function toNsArray(): array
    {
        return array_map(
            fn (Sample $sample): int => $sample->duration()->toNsInt(),
            $this->getItems(),
        );
    }
}
