<?php

declare(strict_types=1);

namespace Dan\Probe\Tests\Synthetic;

use Dan\Probe\Recorder\QueryRecorder;
use Dan\Probe\Scenario\Corpus\SyntheticJsonPathScenario;
use Dan\Probe\Synthetic\SyntheticBlobDefinition;
use Dan\Probe\Synthetic\SyntheticSchemaInstaller;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\DefinitionInstanceRegistry;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Test\TestCaseBase\KernelLifecycleManager;

/**
 * Trust layer 2: Shopware must compile the probe definition into a working
 * repository, and the public Criteria API must resolve its typed JSON paths.
 */
final class SyntheticEntityTest extends TestCase
{
    protected function setUp(): void
    {
        if (($_SERVER['DATABASE_URL'] ?? $_ENV['DATABASE_URL'] ?? null) === null) {
            self::markTestSkipped('DATABASE_URL is not set - kernel integration tests need a database.');
        }
    }

    public function testGeneratedRepositoryExecutesJsonPathCriteria(): void
    {
        $container = KernelLifecycleManager::getKernel()->getContainer();
        $registry = $container->get(DefinitionInstanceRegistry::class);
        self::assertInstanceOf(DefinitionInstanceRegistry::class, $registry);
        $installer = $container->get(SyntheticSchemaInstaller::class);
        self::assertInstanceOf(SyntheticSchemaInstaller::class, $installer);
        $connection = $container->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $recorder = $container->get(QueryRecorder::class);
        self::assertInstanceOf(QueryRecorder::class, $recorder);

        $installer->install();
        $installer->install();

        $repository = $registry->getRepository(SyntheticBlobDefinition::ENTITY_NAME);
        self::assertInstanceOf(EntityRepository::class, $repository);

        $context = Context::createDefaultContext();
        $matchingId = '00000000000000000000000000000951';
        $otherId = '00000000000000000000000000000952';
        $repository->upsert([
            [
                'id' => $matchingId,
                'name' => 'Matching synthetic blob',
                'payload' => [
                    'segment' => 'segment-07',
                    'score' => 951,
                    'active' => true,
                ],
                'rank' => 951,
            ],
            [
                'id' => $otherId,
                'name' => 'Other synthetic blob',
                'payload' => [
                    'segment' => 'segment-08',
                    'score' => 952,
                    'active' => true,
                ],
                'rank' => 952,
            ],
        ], $context);

        try {
            $recorder->start();

            $result = $repository->search((new SyntheticJsonPathScenario())->criteria($context), $context);
            $statements = $recorder->drain();

            self::assertSame([$matchingId], array_values($result->getIds()));
            self::assertNotSame([], array_values(array_filter(
                $statements,
                fn ($statement): bool => str_contains($statement->sql, 'JSON_EXTRACT'),
            )));
        } finally {
            $recorder->stop();
            $repository->delete([
                ['id' => $matchingId],
                ['id' => $otherId],
            ], $context);
        }
    }
}
