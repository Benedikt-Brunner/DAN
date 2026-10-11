<?php

declare(strict_types=1);

namespace Dan\Probe\Execution\Result;

use Dan\Lib\Protocol\ScenarioResultSchemaVersion;

/**
 * The probe-side scenario result of one dan:execute invocation - one
 * measurement block from the harness's point of view. It reports the warmup
 * and measured iteration counts it actually ran so the harness can verify the
 * block against its schedule. Conversion to the CLI protocol happens only at
 * the artifact-writing boundary.
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
        private int $warmupIterations,
        private int $measuredIterations,
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
     *     warmupIterations: int,
     *     measuredIterations: int,
     *     wallNsSamples: list<int>,
     *     statements: list<array{
     *         index: int,
     *         sql: string,
     *         durationsNsSamples: list<int>,
     *         observed: int,
     *         divergence: string
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
            'warmupIterations' => $this->warmupIterations,
            'measuredIterations' => $this->measuredIterations,
            'wallNsSamples' => $this->wallSamplesNs,
            'statements' => array_map(
                fn (StatementResult $statement): array => $statement->toArray(),
                $this->statements,
            ),
        ];
    }
}
