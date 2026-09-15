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
     * Narrows untrusted json_decode() output into samples; the context names
     * the field in the refusal so a malformed artifact points at itself.
     */
    public static function fromDecodedArray(mixed $payload, string $context): self
    {
        if (!is_array($payload) || !array_is_list($payload)) {
            throw new RuntimeException(sprintf('Malformed %s: expected a list of integer nanoseconds.', $context));
        }
        foreach ($payload as $sample) {
            if (!is_int($sample)) {
                throw new RuntimeException(sprintf('Malformed %s: every sample must be an integer.', $context));
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
