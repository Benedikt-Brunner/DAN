<?php

declare(strict_types=1);

namespace Dan\Lib\Protocol;

use RuntimeException;

/**
 * One logically checksummed part of a seeded dataset - an entity, its
 * translations, or an association mapping - as row count plus an
 * order-independent checksum over the values scenarios can observe.
 *
 * @phpstan-type DatasetAspectPayload array{name: string, rows: int, checksum: string}
 */
final class DatasetAspect
{
    public function __construct(
        public readonly string $name,
        public readonly int $rows,
        public readonly string $checksum,
    ) {}

    /** @return DatasetAspectPayload */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'rows' => $this->rows,
            'checksum' => $this->checksum,
        ];
    }

    /**
     * @param array<mixed> $payload
     */
    public static function fromDecodedArray(array $payload): self
    {
        $name = $payload['name'] ?? null;
        $rows = $payload['rows'] ?? null;
        $checksum = $payload['checksum'] ?? null;
        if (!is_string($name) || !is_int($rows) || !is_string($checksum)) {
            throw new RuntimeException('Malformed dataset aspect payload.');
        }

        return new self(name: $name, rows: $rows, checksum: $checksum);
    }
}
