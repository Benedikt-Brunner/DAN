<?php

declare(strict_types=1);

namespace Dan\Harness\RunStore\Artifact;

use Dan\Harness\Measurement\Result\SampleCollection;
use Dan\Harness\Protocol\DatabaseTarget;
use Dan\Lib\Protocol\ScenarioName;
use Dan\Lib\Protocol\ScenarioResultSchemaVersion;
use Dan\Lib\Protocol\Tier;
use RuntimeException;

/**
 * Measurement result of one grid cell. Latency samples accumulate across
 * measurement blocks: merging two block results for the same cell appends
 * samples statement-by-statement.
 *
 * @phpstan-import-type DatabaseTargetPayload from DatabaseTarget
 * @phpstan-import-type StatementProfilePayload from StatementProfile
 *
 * @phpstan-type CellResultPayload array{
 *     schemaVersion: int,
 *     scenario: string,
 *     tier: string,
 *     database: DatabaseTargetPayload,
 *     wallNsSamples: list<int>,
 *     statements: list<StatementProfilePayload>
 * }
 */
final class CellResult
{
    public function __construct(
        public readonly ScenarioName $scenario,
        public readonly Tier $tier,
        public readonly DatabaseTarget $database,
        public readonly SampleCollection $wallSamples,
        public readonly StatementProfileCollection $statements,
    ) {}

    public function merge(self $other): self
    {
        return new self(
            scenario: $this->scenario,
            tier: $this->tier,
            database: $this->database,
            wallSamples: $this->wallSamples->merge($other->wallSamples),
            statements: $this->statements->merge($other->statements),
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
            'wallNsSamples' => $this->wallSamples->toNsArray(),
            'statements' => $this->statements->toArray(),
        ];
    }

    /**
     * @param array<mixed> $payload
     */
    public static function fromDecodedArray(array $payload): self
    {
        $tier = $payload['tier'] ?? null;
        $database = $payload['database'] ?? null;
        if (!is_string($tier) || !is_array($database)) {
            throw new RuntimeException('Malformed cell-result payload: tier or database is invalid.');
        }

        return self::createFromDecodedMeasurements(
            payload: $payload,
            tier: Tier::tryFrom($tier) ?? throw new RuntimeException(sprintf('Malformed cell result: unknown tier "%s".', $tier)),
            database: DatabaseTarget::fromDecodedArray($database),
            expected: CellResultSchemaVersion::getCurrent(),
            artifact: 'cell result',
        );
    }

    /**
     * @param array<mixed> $payload
     */
    public static function fromDecodedScenarioArray(array $payload, Tier $tier, DatabaseTarget $database): self
    {
        return self::createFromDecodedMeasurements(
            payload: $payload,
            tier: $tier,
            database: $database,
            expected: ScenarioResultSchemaVersion::getCurrent(),
            artifact: 'scenario result',
        );
    }

    /**
     * @param array<mixed> $payload
     */
    private static function createFromDecodedMeasurements(
        array $payload,
        Tier $tier,
        DatabaseTarget $database,
        CellResultSchemaVersion|ScenarioResultSchemaVersion $expected,
        string $artifact,
    ): self {
        $schemaVersion = $payload['schemaVersion'] ?? null;
        $scenario = $payload['scenario'] ?? null;
        $wallSamples = $payload['wallNsSamples'] ?? null;
        $statements = $payload['statements'] ?? null;
        if (
            !is_int($schemaVersion)
            || !is_string($scenario)
            || !is_array($wallSamples)
            || !array_is_list($wallSamples)
            || !is_array($statements)
        ) {
            throw new RuntimeException('Malformed measurement-result payload.');
        }
        foreach ($wallSamples as $wallSample) {
            if (!is_int($wallSample)) {
                throw new RuntimeException('Malformed measurement result: wall samples must be a list of integers.');
            }
        }
        self::requireSchemaVersion(version: $schemaVersion, expected: $expected, artifact: $artifact);

        return new self(
            scenario: ScenarioName::fromString($scenario),
            tier: $tier,
            database: $database,
            wallSamples: SampleCollection::fromArray($wallSamples),
            statements: StatementProfileCollection::fromDecodedArray($statements),
        );
    }

    private static function requireSchemaVersion(
        int $version,
        CellResultSchemaVersion|ScenarioResultSchemaVersion $expected,
        string $artifact,
    ): void {
        if ($version !== $expected->value) {
            throw new RuntimeException(sprintf('Unsupported %s schema version %d (expected %d).', $artifact, $version, $expected->value));
        }
    }
}
