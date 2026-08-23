<?php

declare(strict_types=1);

namespace Dan\Harness\Protocol;

use Dan\Lib\Protocol\Tier;
use RuntimeException;

/**
 * The fully-resolved measurement protocol of a run. The matrix is chosen via
 * CLI flags, but the resolved protocol is always frozen into the run manifest
 * so every profile is self-describing, and diffs across mismatched protocols
 * can be detected and refused.
 *
 * @phpstan-import-type DatabaseTargetPayload from DatabaseTarget
 *
 * @phpstan-type ProtocolPayload array{
 *     databases: list<DatabaseTargetPayload>,
 *     tiers: list<string>,
 *     warmupIterations: int,
 *     measuredIterations: int,
 *     blocks: int,
 *     scenarioFilter: string|null
 * }
 */
final class Protocol
{
    /**
     * @param list<DatabaseTarget> $databases
     * @param list<Tier> $tiers
     */
    public function __construct(
        public readonly array $databases,
        public readonly array $tiers,
        public readonly int $warmupIterations,
        public readonly int $measuredIterations,
        public readonly int $blocks,
        public readonly ?string $scenarioFilter,
    ) {}

    public function equals(self $other): bool
    {
        return $this->toArray() === $other->toArray();
    }

    /** @return ProtocolPayload */
    public function toArray(): array
    {
        return [
            'databases' => array_map(fn (DatabaseTarget $db) => $db->toArray(), $this->databases),
            'tiers' => array_map(fn (Tier $tier) => $tier->value, $this->tiers),
            'warmupIterations' => $this->warmupIterations,
            'measuredIterations' => $this->measuredIterations,
            'blocks' => $this->blocks,
            'scenarioFilter' => $this->scenarioFilter,
        ];
    }

    /**
     * @param ProtocolPayload $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            databases: array_map(DatabaseTarget::fromArray(...), $payload['databases']),
            tiers: array_map(
                fn (string $tier): Tier => Tier::tryFrom($tier) ?? throw new RuntimeException(sprintf('Malformed protocol: unknown tier "%s".', $tier)),
                $payload['tiers'],
            ),
            warmupIterations: $payload['warmupIterations'],
            measuredIterations: $payload['measuredIterations'],
            blocks: $payload['blocks'],
            scenarioFilter: $payload['scenarioFilter'],
        );
    }

    /**
     * @param array<mixed> $payload
     */
    public static function fromDecodedArray(array $payload): self
    {
        $databasePayloads = $payload['databases'] ?? null;
        $tierPayloads = $payload['tiers'] ?? null;
        $warmupIterations = $payload['warmupIterations'] ?? null;
        $measuredIterations = $payload['measuredIterations'] ?? null;
        $blocks = $payload['blocks'] ?? null;
        $scenarioFilter = $payload['scenarioFilter'] ?? null;
        if (
            !is_array($databasePayloads)
            || !array_is_list($databasePayloads)
            || !is_array($tierPayloads)
            || !array_is_list($tierPayloads)
            || !is_int($warmupIterations)
            || !is_int($measuredIterations)
            || !is_int($blocks)
            || ($scenarioFilter !== null && !is_string($scenarioFilter))
        ) {
            throw new RuntimeException('Malformed protocol payload.');
        }

        $databases = [];
        foreach ($databasePayloads as $databasePayload) {
            if (!is_array($databasePayload)) {
                throw new RuntimeException('Malformed protocol: database targets must be objects.');
            }
            $databases[] = DatabaseTarget::fromDecodedArray($databasePayload);
        }
        $tiers = [];
        foreach ($tierPayloads as $tierPayload) {
            if (!is_string($tierPayload)) {
                throw new RuntimeException('Malformed protocol: tiers must be strings.');
            }
            $tiers[] = Tier::tryFrom($tierPayload) ?? throw new RuntimeException(sprintf('Malformed protocol: unknown tier "%s".', $tierPayload));
        }

        return new self(
            databases: $databases,
            tiers: $tiers,
            warmupIterations: $warmupIterations,
            measuredIterations: $measuredIterations,
            blocks: $blocks,
            scenarioFilter: $scenarioFilter,
        );
    }
}
