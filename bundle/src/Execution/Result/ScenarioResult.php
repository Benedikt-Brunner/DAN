<?php

declare(strict_types=1);

namespace Dan\Probe\Execution\Result;

use Dan\Lib\Protocol\ScenarioResultSchemaVersion;

/**
 * The probe-side scenario result. Conversion to the CLI protocol happens only
 * at the artifact-writing boundary.
 */
final readonly class ScenarioResult
{
    /**
     * @param list<int> $wallSamplesNs
     * @param list<StatementResult> $statements
     */
    public function __construct(
        private string $scenario,
        private string $entity,
        private ?string $dalVersion,
        private int $iterations,
        private array $wallSamplesNs,
        private array $statements,
    ) {}

    public function scenario(): string
    {
        return $this->scenario;
    }

    /**
     * This array is the CLI contract consumed by the harness. Changing its
     * shape requires updating both packages and bumping the schema version.
     *
     * @return array{
     *     schemaVersion: int,
     *     scenario: string,
     *     entity: string,
     *     dalVersion: string|null,
     *     iterations: int,
     *     wallNsSamples: list<int>,
     *     statements: list<array{
     *         index: int,
     *         sql: string,
     *         durationsNsSamples: list<int>,
     *         divergent: bool
     *     }>
     * }
     */
    public function toArray(): array
    {
        return [
            'schemaVersion' => ScenarioResultSchemaVersion::getCurrent()->value,
            'scenario' => $this->scenario,
            'entity' => $this->entity,
            'dalVersion' => $this->dalVersion,
            'iterations' => $this->iterations,
            'wallNsSamples' => $this->wallSamplesNs,
            'statements' => array_map(
                fn (StatementResult $statement): array => $statement->toArray(),
                $this->statements,
            ),
        ];
    }
}
