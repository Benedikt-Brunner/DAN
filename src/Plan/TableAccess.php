<?php

declare(strict_types=1);

namespace Dan\Harness\Plan;

/**
 * How the engine reads one table in a plan: the access method, the index it
 * chose (null for a scan without one) and its row estimate for the access.
 * Engines name these differently; here they share a vocabulary.
 */
final class TableAccess
{
    public function __construct(
        public readonly string $table,
        public readonly string $accessType,
        public readonly ?string $key,
        public readonly ?int $estimatedRows,
    ) {}

    public function describe(): string
    {
        return sprintf(
            '%s %s%s%s',
            $this->accessType,
            $this->table,
            $this->key === null ? '' : ' via ' . $this->key,
            $this->estimatedRows === null ? '' : sprintf(' (~%d rows)', $this->estimatedRows),
        );
    }
}
