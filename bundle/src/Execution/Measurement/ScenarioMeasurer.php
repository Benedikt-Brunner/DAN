<?php

declare(strict_types=1);

namespace Dan\Probe\Execution\Measurement;

use Composer\InstalledVersions;
use Dan\Lib\Time\Timestamp;
use Dan\Probe\Execution\Result\ScenarioResult;
use Dan\Probe\Recorder\QueryRecorder;
use Dan\Probe\Scenario\Scenario;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\DefinitionInstanceRegistry;

/**
 * Executes one scenario through the public DAL and records its measurements.
 *
 * @api Symfony service instantiated by the dependency-injection container.
 */
final readonly class ScenarioMeasurer
{
    public function __construct(
        private DefinitionInstanceRegistry $definitionRegistry,
        private QueryRecorder $recorder,
    ) {}

    public function measure(
        Scenario $scenario,
        Context $context,
        int $warmup,
        int $iterations,
    ): ScenarioResult {
        $repository = $this->definitionRegistry->getRepository($scenario->entity());
        $this->recorder->start();

        try {
            for ($iteration = 0; $iteration < $warmup; ++$iteration) {
                $repository->search($scenario->criteria($context), $context);
                $this->recorder->drain();
            }

            $wallSamplesNs = [];
            /** @var array<int, StatementMeasurementAccumulator> $statements */
            $statements = [];
            for ($iteration = 0; $iteration < $iterations; ++$iteration) {
                $this->recorder->drain();
                $startedAt = Timestamp::now();
                $repository->search($scenario->criteria($context), $context);
                $wallSamplesNs[] = $startedAt->elapsed()->toNsInt();

                foreach ($this->recorder->drain() as $index => $recordedStatement) {
                    $statements[$index] ??= new StatementMeasurementAccumulator(
                        index: $index,
                        sql: $recordedStatement->sql,
                    );
                    $statements[$index]->record($recordedStatement);
                }
            }
        } finally {
            $this->recorder->stop();
        }

        return new ScenarioResult(
            scenario: $scenario->name(),
            entity: $scenario->entity(),
            dalVersion: InstalledVersions::isInstalled('shopware/core')
                ? InstalledVersions::getPrettyVersion('shopware/core')
                : null,
            iterations: $iterations,
            wallSamplesNs: $wallSamplesNs,
            statements: array_map(
                fn (StatementMeasurementAccumulator $statement) => $statement->result(),
                array_values($statements),
            ),
        );
    }
}
