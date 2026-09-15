<?php

declare(strict_types=1);

namespace Dan\Harness\RunStore\Artifact;

use Dan\Harness\Measurement\Result\SampleCollection;
use Dan\Harness\Measurement\Scheduling\MeasurementBlock;
use Dan\Harness\Protocol\DatabaseTarget;
use Dan\Lib\Protocol\ScenarioName;
use Dan\Lib\Protocol\ScenarioResultSchemaVersion;
use Dan\Lib\Protocol\Tier;
use RuntimeException;

/**
 * Measurement result of one grid cell, block by block. Every dan:execute
 * invocation contributes one measurement block; merging block results for
 * the same cell unions the blocks by their position in the session schedule.
 * Pooled views (all wall samples, the statement sequence with samples
 * accumulated across blocks) are derived for presentation - block identity
 * stays in the artifact so block-aware inference and order-effect
 * diagnostics never depend on process-local knowledge.
 *
 * @phpstan-import-type DatabaseTargetPayload from DatabaseTarget
 * @phpstan-import-type BlockResultPayload from BlockResult
 *
 * @phpstan-type CellResultPayload array{
 *     schemaVersion: int,
 *     scenario: string,
 *     tier: string,
 *     database: DatabaseTargetPayload,
 *     blocks: list<BlockResultPayload>
 * }
 */
final class CellResult
{
    public function __construct(
        public readonly ScenarioName $scenario,
        public readonly Tier $tier,
        public readonly DatabaseTarget $database,
        public readonly BlockResultCollection $blocks,
    ) {}

    public function wallSamples(): SampleCollection
    {
        return $this->blocks->pooledWallSamples();
    }

    public function statements(): StatementProfileCollection
    {
        return $this->blocks->pooledStatements();
    }

    public function merge(self $other): self
    {
        return new self(
            scenario: $this->scenario,
            tier: $this->tier,
            database: $this->database,
            blocks: $this->blocks->merge($other->blocks),
        );
    }

    /** @return CellResultPayload */
    public function toArray(): array
    {
        return [
            'schemaVersion' => CellResultSchemaVersion::getCurrent()->value,
            'scenario' => $this->scenario->toString(),
            'tier' => $this->tier->value,
            'database' => $this->database->toArray(),
            'blocks' => $this->blocks->toArray(),
        ];
    }

    /**
     * @param array<mixed> $payload
     */
    public static function fromDecodedArray(array $payload): self
    {
        $schemaVersion = $payload['schemaVersion'] ?? null;
        $scenario = $payload['scenario'] ?? null;
        $tier = $payload['tier'] ?? null;
        $database = $payload['database'] ?? null;
        $blocks = $payload['blocks'] ?? null;
        if (!is_int($schemaVersion) || !is_string($scenario) || !is_string($tier) || !is_array($database) || !is_array($blocks)) {
            throw new RuntimeException('Malformed cell-result payload.');
        }
        if ($schemaVersion !== CellResultSchemaVersion::getCurrent()->value) {
            throw new RuntimeException(sprintf('Unsupported cell result schema version %d (expected %d).', $schemaVersion, CellResultSchemaVersion::getCurrent()->value));
        }

        return new self(
            scenario: ScenarioName::fromString($scenario),
            tier: Tier::tryFrom($tier) ?? throw new RuntimeException(sprintf('Malformed cell result: unknown tier "%s".', $tier)),
            database: DatabaseTarget::fromDecodedArray($database),
            blocks: BlockResultCollection::fromDecodedArray($blocks),
        );
    }

    /**
     * Adopts one dan:execute result as the measurement block it was scheduled
     * as. The probe reports what it actually ran; anything that disagrees
     * with the schedule (iteration or warmup counts, one wall sample per
     * iteration) is refused rather than silently recorded under the wrong
     * block identity.
     *
     * @param array<mixed> $payload
     */
    public static function fromDecodedScenarioArray(array $payload, Tier $tier, DatabaseTarget $database, MeasurementBlock $block): self
    {
        $schemaVersion = $payload['schemaVersion'] ?? null;
        $scenario = $payload['scenario'] ?? null;
        $warmupIterations = $payload['warmupIterations'] ?? null;
        $measuredIterations = $payload['measuredIterations'] ?? null;
        $wallSamples = $payload['wallNsSamples'] ?? null;
        $statements = $payload['statements'] ?? null;
        if (!is_int($schemaVersion) || !is_string($scenario) || !is_int($warmupIterations) || !is_int($measuredIterations) || !is_array($statements)) {
            throw new RuntimeException('Malformed scenario-result payload.');
        }
        $expected = ScenarioResultSchemaVersion::getCurrent();
        if ($schemaVersion !== $expected->value) {
            throw new RuntimeException(sprintf('Unsupported scenario result schema version %d (expected %d).', $schemaVersion, $expected->value));
        }
        $wallSamples = SampleCollection::fromDecodedArray(payload: $wallSamples, context: 'scenario result wall samples');

        if ($measuredIterations !== $block->iterations) {
            throw new RuntimeException(sprintf('Scenario "%s": the probe measured %d iterations but block %d was scheduled with %d.', $scenario, $measuredIterations, $block->blockIndex, $block->iterations));
        }
        if ($warmupIterations !== $block->warmupIterations) {
            throw new RuntimeException(sprintf('Scenario "%s": the probe ran %d warmup iterations but block %d was scheduled with %d.', $scenario, $warmupIterations, $block->blockIndex, $block->warmupIterations));
        }
        if (count($wallSamples) !== $measuredIterations) {
            throw new RuntimeException(sprintf('Scenario "%s": %d wall samples for %d measured iterations - one sample per iteration is the contract.', $scenario, count($wallSamples), $measuredIterations));
        }

        return new self(
            scenario: ScenarioName::fromString($scenario),
            tier: $tier,
            database: $database,
            blocks: BlockResultCollection::inExecutionOrder([
                new BlockResult(
                    blockIndex: $block->blockIndex,
                    executionOrder: $block->executionOrder,
                    warmupIterations: $warmupIterations,
                    wallSamples: $wallSamples,
                    statements: StatementProfileCollection::fromDecodedArray($statements),
                ),
            ]),
        );
    }
}
