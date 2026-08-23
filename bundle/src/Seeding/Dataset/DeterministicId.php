<?php

declare(strict_types=1);

namespace Dan\Probe\Seeding\Dataset;

use Stringable;

/**
 * Deterministic UUIDs derived from stable keys. The same key always yields
 * the same id, which makes seeding idempotent and therefore resumable.
 */
final readonly class DeterministicId implements Stringable
{
    private function __construct(
        private string $id,
    ) {}

    public static function create(string $key): self
    {
        $bytes = md5('dan:' . $key, true);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return new self(bin2hex($bytes));
    }

    public function __toString(): string
    {
        return $this->id;
    }
}
