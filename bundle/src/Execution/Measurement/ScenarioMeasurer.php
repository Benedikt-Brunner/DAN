<?php

declare(strict_types=1);

namespace Dan\Probe\Execution\Measurement;

use Composer\InstalledVersions;
use Dan\Lib\Protocol\ResultSet;
use Dan\Lib\Time\Timestamp;
use Dan\Probe\Execution\Result\CapturedPlan;
use Dan\Probe\Execution\Result\ScenarioResult;
use Dan\Probe\Recorder\QueryRecorder;
use Dan\Probe\Recorder\RecordedStatement;
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
        private QueryPlanCapture $planCapture,
    ) {}

    /**
     * @param bool $capturePlans explain every recorded statement after timing;
     *                           the harness asks for it once per cell
     */
    public function measure(
        Scenario $scenario,
        int $warmup,
        int $iterations,
        bool $capturePlans,
    ): ScenarioResult {
        $context = self::contextFor($scenario);
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
            $resultSets = new ResultSetAccumulator();
            /** @var list<RecordedStatement> $lastIteration */
            $lastIteration = [];
            for ($iteration = 0; $iteration < $iterations; ++$iteration) {
                $this->recorder->drain();
                $startedAt = Timestamp::now();
                $searchResult = $repository->search($scenario->criteria($context), $context);
                $wallSamplesNs[] = $startedAt->elapsed()->toNsInt();
                // Outside the timed section: reducing the result is DAN's
                // bookkeeping, not the DAL's work.
                $resultSets->observe(new ResultSet(ids: array_values($searchResult->getIds()), total: $searchResult->getTotal()));

                $lastIteration = $this->recorder->drain();
                foreach ($lastIteration as $index => $recordedStatement) {
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

        // Plans come after timing, with the recorder stopped, so EXPLAIN
        // never shows up in the samples. Bound to the last iteration's
        // parameter values, the ones the engine actually planned for.
        /** @var array<int, CapturedPlan> $plans */
        $plans = [];
        if ($capturePlans) {
            foreach ($lastIteration as $index => $recordedStatement) {
                $plans[$index] = $this->planCapture->capture($recordedStatement);
            }
        }

        return new ScenarioResult(
            scenario: $scenario->name(),
            entity: $scenario->entity(),
            dalVersion: InstalledVersions::isInstalled('shopware/core')
                ? InstalledVersions::getPrettyVersion('shopware/core')
                : null,
            warmupIterations: $warmup,
            measuredIterations: $iterations,
            resultSet: $resultSets->resultSet(),
            resultSetConsistent: $resultSets->consistent(),
            wallSamplesNs: $wallSamplesNs,
            statements: array_map(
                fn (int $index, StatementMeasurementAccumulator $statement) => $statement->result(iterations: $iterations, plan: $plans[$index] ?? null),
                array_keys($statements),
                array_values($statements),
            ),
        );
    }

    /**
     * The scenario decides whether inheritance is considered; everything
     * else is the default system context.
     */
    public static function contextFor(Scenario $scenario): Context
    {
        $context = Context::createDefaultContext();
        $context->setConsiderInheritance($scenario->considerInheritance());

        return $context;
    }
}
