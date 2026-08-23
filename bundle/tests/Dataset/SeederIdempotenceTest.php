<?php

declare(strict_types=1);

namespace Dan\Probe\Tests\Dataset;

use Dan\Probe\Seeding\Dataset\DeterministicId;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\DefinitionInstanceRegistry;
use Shopware\Core\Framework\Test\TestCaseBase\KernelLifecycleManager;
use Shopware\Core\System\Tax\TaxDefinition;

/**
 * Trust layer 2: dataset determinism. Deterministic ids + upserts must make
 * seeding idempotent - re-running converges on the identical dataset, which
 * is what makes the snapshot cache and resumable seeding sound.
 *
 * Full byte-identical-dump determinism across engines is asserted by the
 * nightly calibration pipeline, not here - this covers the write-path
 * mechanism.
 */
final class SeederIdempotenceTest extends TestCase
{
    protected function setUp(): void
    {
        if (($_SERVER['DATABASE_URL'] ?? $_ENV['DATABASE_URL'] ?? null) === null) {
            self::markTestSkipped('DATABASE_URL is not set - kernel integration tests need a database.');
        }
    }

    public function testUpsertingTheSameDeterministicPayloadTwiceCreatesNoNewRows(): void
    {
        $container = KernelLifecycleManager::getKernel()->getContainer();
        $registry = $container->get(DefinitionInstanceRegistry::class);
        self::assertInstanceOf(DefinitionInstanceRegistry::class, $registry);
        $connection = $container->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $context = Context::createDefaultContext();

        $taxRepository = $registry->getRepository(TaxDefinition::ENTITY_NAME);
        $productRepository = $registry->getRepository(ProductDefinition::ENTITY_NAME);

        $taxId = (string) DeterministicId::create('test:tax');
        $payload = [
            [
                'id' => (string) DeterministicId::create('test:product:0'),
                'productNumber' => 'DAN-TEST-00000000',
                'name' => 'DAN Idempotence Probe',
                'stock' => 1,
                'tax' => [
                    'id' => $taxId,
                    'name' => 'DAN Test 19%',
                    'taxRate' => 19.0,
                ],
                'price' => [[
                    'currencyId' => Defaults::CURRENCY,
                    'gross' => 11.99,
                    'net' => 10.08,
                    'linked' => false,
                ]],
            ],
        ];

        $productRepository->upsert($payload, $context);
        $countAfterFirst = $connection->fetchOne('SELECT COUNT(*) FROM product');
        self::assertIsNumeric($countAfterFirst);

        $productRepository->upsert($payload, $context);
        $countAfterSecond = $connection->fetchOne('SELECT COUNT(*) FROM product');
        self::assertIsNumeric($countAfterSecond);

        self::assertSame($countAfterFirst, $countAfterSecond);

        // Cleanup so repeated local test runs stay idempotent themselves.
        $productRepository->delete([['id' => (string) DeterministicId::create('test:product:0')]], $context);
        $taxRepository->delete([['id' => $taxId]], $context);
    }
}
