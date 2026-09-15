<?php

declare(strict_types=1);

namespace Dan\Probe\Tests\Scenario;

use Dan\Lib\Protocol\Tier;
use Dan\Probe\Execution\Measurement\ScenarioMeasurer;
use Dan\Probe\Scenario\Scenario;
use Dan\Probe\Seeding\Dataset\DatasetSeeder;
use Dan\Probe\Seeding\Dataset\TierSpec;
use Dan\Probe\Seeding\Progress\SeedProgressReporter;
use Dan\Probe\Synthetic\SyntheticSchemaInstaller;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\DefinitionInstanceRegistry;
use Shopware\Core\Framework\Test\TestCaseBase\KernelLifecycleManager;

/**
 * Trust layer 2 for the corpus: against a real Shopware seeded to tier S,
 * every scenario returns exactly the total its dataset rules predict, and
 * returns the same ids twice. Runs inside a transaction that is rolled
 * back, so the seeded tier never leaks into other kernel tests.
 */
final class CorpusCoverageTest extends TestCase
{
    protected function setUp(): void
    {
        if (($_SERVER['DATABASE_URL'] ?? $_ENV['DATABASE_URL'] ?? null) === null) {
            self::markTestSkipped('DATABASE_URL is not set - kernel integration tests need a database.');
        }
    }

    public function testEveryScenarioReturnsItsExpectedTotalDeterministicallyAtTierS(): void
    {
        $container = KernelLifecycleManager::getKernel()->getContainer();
        $connection = $container->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $registry = $container->get(DefinitionInstanceRegistry::class);
        self::assertInstanceOf(DefinitionInstanceRegistry::class, $registry);
        $installer = $container->get(SyntheticSchemaInstaller::class);
        self::assertInstanceOf(SyntheticSchemaInstaller::class, $installer);
        // Constructed directly: the compiled container inlines both the
        // seeder and the registry into their commands.
        $seeder = new DatasetSeeder(definitionRegistry: $registry, syntheticSchemaInstaller: $installer);

        $context = Context::createDefaultContext();
        $spec = TierSpec::forTier(Tier::S);

        $connection->beginTransaction();
        try {
            $seeder->seed(spec: $spec, context: $context, progress: new class implements SeedProgressReporter {
                public function seeding(string $what, int $total): void {}

                public function seeded(int $seeded, int $total): void {}

                public function finished(): void {}
            });

            $failures = [];
            foreach (self::corpus() as $scenario) {
                $failure = $this->verify(scenario: $scenario, spec: $spec, registry: $registry);
                if ($failure !== null) {
                    $failures[] = $failure;
                }
            }
            self::assertSame([], $failures, implode("\n", $failures));
        } finally {
            $connection->rollBack();
        }
    }

    /**
     * @return list<Scenario>
     */
    private static function corpus(): array
    {
        $scenarios = [];
        foreach (glob(__DIR__ . '/../../src/Scenario/Corpus/*.php') ?: [] as $file) {
            /** @var class-string $class */
            $class = 'Dan\\Probe\\Scenario\\Corpus\\' . basename($file, '.php');
            $scenario = new $class();
            if (!$scenario instanceof Scenario) {
                throw new RuntimeException(sprintf('%s is not a scenario.', $class));
            }
            $scenarios[] = $scenario;
        }

        return $scenarios;
    }

    private function verify(Scenario $scenario, TierSpec $spec, DefinitionInstanceRegistry $registry): ?string
    {
        $context = ScenarioMeasurer::contextFor($scenario);
        $repository = $registry->getRepository($scenario->entity());
        $first = $repository->search($scenario->criteria($context), $context);
        $second = $repository->search($scenario->criteria($context), $context);

        $expected = $scenario->expectedTotal($spec);
        if ($first->getTotal() !== $expected) {
            return sprintf('%s: expected total %d, got %d', $scenario->name(), $expected, $first->getTotal());
        }
        if (array_values($first->getIds()) !== array_values($second->getIds())) {
            return sprintf('%s: two identical searches returned different ids', $scenario->name());
        }
        $limit = $scenario->criteria($context)->getLimit();
        if ($limit !== null && count($first->getIds()) !== min($limit, $expected)) {
            return sprintf('%s: expected %d ids on the first page, got %d', $scenario->name(), min($limit, $expected), count($first->getIds()));
        }

        return null;
    }
}
