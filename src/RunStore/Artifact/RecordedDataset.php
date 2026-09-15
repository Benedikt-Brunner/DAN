<?php

declare(strict_types=1);

namespace Dan\Harness\RunStore\Artifact;

use Dan\Harness\Protocol\DatabaseTarget;
use Dan\Lib\Protocol\DatasetFingerprint;
use Dan\Lib\Protocol\Tier;
use RuntimeException;

/**
 * The logical fingerprint of one seeded dataset of a run, keyed by the grid
 * coordinates it was seeded for. Stored per (tier x database) so two runs
 * can prove - or disprove - that their cells measured the same data.
 *
 * @phpstan-import-type DatabaseTargetPayload from DatabaseTarget
 * @phpstan-import-type DatasetFingerprintPayload from DatasetFingerprint
 *
 * @phpstan-type RecordedDatasetPayload array{tier: string, database: DatabaseTargetPayload, fingerprint: DatasetFingerprintPayload}
 */
final class RecordedDataset
{
    public function __construct(
        public readonly Tier $tier,
        public readonly DatabaseTarget $database,
        public readonly DatasetFingerprint $fingerprint,
    ) {
        if ($fingerprint->tier !== $tier) {
            throw new RuntimeException(sprintf('A fingerprint for tier %s cannot be recorded as the tier %s dataset.', $fingerprint->tier->value, $tier->value));
        }
    }

    public function fileName(): string
    {
        return sprintf('%s--%s.json', $this->tier->value, $this->database->id());
    }

    /** @return RecordedDatasetPayload */
    public function toArray(): array
    {
        return [
            'tier' => $this->tier->value,
            'database' => $this->database->toArray(),
            'fingerprint' => $this->fingerprint->toArray(),
        ];
    }

    /**
     * @param array<mixed> $payload
     */
    public static function fromDecodedArray(array $payload): self
    {
        $tier = $payload['tier'] ?? null;
        $database = $payload['database'] ?? null;
        $fingerprint = $payload['fingerprint'] ?? null;
        if (!is_string($tier) || !is_array($database) || !is_array($fingerprint)) {
            throw new RuntimeException('Malformed recorded dataset payload.');
        }

        return new self(
            tier: Tier::tryFrom($tier) ?? throw new RuntimeException(sprintf('Malformed recorded dataset: unknown tier "%s".', $tier)),
            database: DatabaseTarget::fromDecodedArray($database),
            fingerprint: DatasetFingerprint::fromDecodedArray($fingerprint),
        );
    }
}
