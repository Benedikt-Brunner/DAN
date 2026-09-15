<?php

declare(strict_types=1);

namespace Dan\Lib\Protocol;

use RuntimeException;

/**
 * The logical content of a seeded dataset, independent of physical row
 * layout: one checksummed aspect per seeded entity, translation set and
 * association mapping. Two implementations may only be compared over
 * datasets with equal fingerprints - deterministic seed input does not by
 * itself prove the DAL under test wrote the same rows. Computed by the
 * probe, stored and compared by the harness.
 *
 * @phpstan-import-type DatasetAspectPayload from DatasetAspect
 *
 * @phpstan-type DatasetFingerprintPayload array{schemaVersion: int, tier: string, aspects: list<DatasetAspectPayload>}
 */
final class DatasetFingerprint
{
    /**
     * @param list<DatasetAspect> $aspects
     */
    public function __construct(
        public readonly Tier $tier,
        public readonly array $aspects,
    ) {}

    public function equals(self $other): bool
    {
        return $this->differences($other) === [];
    }

    /**
     * Aspect-level diagnostics: what differs and how, so a mismatch points
     * at the entity or mapping to look at instead of at "the database".
     *
     * @return list<string>
     */
    public function differences(self $other): array
    {
        $differences = [];
        if ($this->tier !== $other->tier) {
            $differences[] = sprintf('tier %s vs %s', $this->tier->value, $other->tier->value);
        }
        $theirs = [];
        foreach ($other->aspects as $aspect) {
            $theirs[$aspect->name] = $aspect;
        }
        foreach ($this->aspects as $aspect) {
            $counterpart = $theirs[$aspect->name] ?? null;
            unset($theirs[$aspect->name]);
            if ($counterpart === null) {
                $differences[] = sprintf('%s: missing on the other side', $aspect->name);

                continue;
            }
            if ($aspect->rows !== $counterpart->rows) {
                $differences[] = sprintf('%s: %d vs %d rows', $aspect->name, $aspect->rows, $counterpart->rows);
            } elseif ($aspect->checksum !== $counterpart->checksum) {
                $differences[] = sprintf('%s: same %d rows, different values', $aspect->name, $aspect->rows);
            }
        }
        foreach ($theirs as $aspect) {
            $differences[] = sprintf('%s: only on the other side', $aspect->name);
        }

        return $differences;
    }

    /** @return DatasetFingerprintPayload */
    public function toArray(): array
    {
        return [
            'schemaVersion' => DatasetFingerprintSchemaVersion::getCurrent()->value,
            'tier' => $this->tier->value,
            'aspects' => array_map(fn (DatasetAspect $aspect): array => $aspect->toArray(), $this->aspects),
        ];
    }

    /**
     * @param array<mixed> $payload
     */
    public static function fromDecodedArray(array $payload): self
    {
        $schemaVersion = $payload['schemaVersion'] ?? null;
        $tier = $payload['tier'] ?? null;
        $aspects = $payload['aspects'] ?? null;
        if (!is_int($schemaVersion) || !is_string($tier) || !is_array($aspects) || !array_is_list($aspects)) {
            throw new RuntimeException('Malformed dataset fingerprint payload.');
        }
        $expected = DatasetFingerprintSchemaVersion::getCurrent();
        if ($schemaVersion !== $expected->value) {
            throw new RuntimeException(sprintf('Unsupported dataset fingerprint schema version %d (expected %d).', $schemaVersion, $expected->value));
        }
        $decodedAspects = [];
        foreach ($aspects as $aspect) {
            if (!is_array($aspect)) {
                throw new RuntimeException('Malformed dataset fingerprint: aspects must be objects.');
            }
            $decodedAspects[] = DatasetAspect::fromDecodedArray($aspect);
        }

        return new self(
            tier: Tier::tryFrom($tier) ?? throw new RuntimeException(sprintf('Malformed dataset fingerprint: unknown tier "%s".', $tier)),
            aspects: $decodedAspects,
        );
    }
}
