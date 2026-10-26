<?php

declare(strict_types=1);

namespace Dan\Probe\Tests\Dataset;

use Dan\Lib\Protocol\DatasetAspect;
use Dan\Lib\Protocol\DatasetFingerprint;
use Dan\Lib\Protocol\Tier;
use Dan\Probe\Seeding\Dataset\DeterministicId;
use Dan\Probe\Seeding\Fingerprint\DatasetFingerprinter;
use Dan\Probe\Synthetic\SyntheticSchemaInstaller;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\Category\CategoryDefinition;
use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\DefinitionInstanceRegistry;
use Shopware\Core\Framework\Test\TestCaseBase\KernelLifecycleManager;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\Tax\TaxDefinition;

/**
 * Trust layer 2: the logical fingerprint sees every change a scenario could
 * observe - missing rows, changed values, changed mappings - and nothing a
 * scenario could not: timestamps and rows the seeder does not own.
 */
final class DatasetFingerprintTest extends TestCase
{
    private Connection $connection;
    private DefinitionInstanceRegistry $registry;
    private Context $context;

    /** @var list<string> */
    private array $productIds = [];
    /** @var list<string> */
    private array $categoryIds = [];

    protected function setUp(): void
    {
        if (($_SERVER['DATABASE_URL'] ?? $_ENV['DATABASE_URL'] ?? null) === null) {
            self::markTestSkipped('DATABASE_URL is not set - kernel integration tests need a database.');
        }
        $container = KernelLifecycleManager::getKernel()->getContainer();
        $registry = $container->get(DefinitionInstanceRegistry::class);
        self::assertInstanceOf(DefinitionInstanceRegistry::class, $registry);
        $connection = $container->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $installer = $container->get(SyntheticSchemaInstaller::class);
        self::assertInstanceOf(SyntheticSchemaInstaller::class, $installer);
        $installer->install();
        $this->registry = $registry;
        $this->connection = $connection;
        $this->context = Context::createDefaultContext();
    }

    protected function tearDown(): void
    {
        if ($this->productIds === []) {
            return;
        }
        $this->registry->getRepository(ProductDefinition::ENTITY_NAME)->delete(array_map(fn (string $id): array => ['id' => $id], $this->productIds), $this->context);
        $this->registry->getRepository(CategoryDefinition::ENTITY_NAME)->delete(array_map(fn (string $id): array => ['id' => $id], $this->categoryIds), $this->context);
        $this->registry->getRepository(TaxDefinition::ENTITY_NAME)->delete([['id' => (string) DeterministicId::create('tax:default')]], $this->context);
    }

    public function testSeesEveryObservableChangeAndIgnoresTimestamps(): void
    {
        $this->seedTwoProductsInTwoCategories();
        $fingerprinter = new DatasetFingerprinter($this->connection);
        $seeded = $fingerprinter->fingerprint(Tier::S);
        self::assertSame(2, self::aspect(fingerprint: $seeded, name: 'product')->rows);
        self::assertSame(2, self::aspect(fingerprint: $seeded, name: 'product.name')->rows);
        self::assertSame(2, self::aspect(fingerprint: $seeded, name: 'product.categories')->rows);
        self::assertSame(2, self::aspect(fingerprint: $seeded, name: 'category')->rows);
        self::assertSame(1, self::aspect(fingerprint: $seeded, name: 'tax')->rows);

        // Stable over time and over re-computation.
        self::assertTrue($seeded->equals($fingerprinter->fingerprint(Tier::S)));
        // Only the timestamp moves: not an observable difference.
        $this->connection->executeStatement('UPDATE `product` SET `updated_at` = NOW(3) WHERE `product_number` LIKE :numbers', ['numbers' => 'DAN-%']);
        self::assertTrue($seeded->equals($fingerprinter->fingerprint(Tier::S)), 'Touching updated_at must not change the fingerprint.');

        // A changed field value: same rows, different values.
        $this->connection->executeStatement('UPDATE `product` SET `stock` = `stock` + 1 WHERE `id` = :id', ['id' => Uuid::fromHexToBytes($this->productIds[0])]);
        $changedStock = $fingerprinter->fingerprint(Tier::S);
        self::assertSame(['product: same 2 rows, different values'], $seeded->differences($changedStock));

        // A changed many-to-many mapping.
        $this->connection->executeStatement(
            'DELETE FROM `product_category` WHERE `product_id` = :product AND `category_id` = :category',
            [
                'product' => Uuid::fromHexToBytes($this->productIds[1]),
                'category' => Uuid::fromHexToBytes($this->categoryIds[1]),
            ],
        );
        $unmapped = $fingerprinter->fingerprint(Tier::S);
        self::assertContains('product.categories: 2 vs 1 rows', $changedStock->differences($unmapped));

        // A missing row.
        $this->registry->getRepository(ProductDefinition::ENTITY_NAME)->delete([['id' => $this->productIds[1]]], $this->context);
        $missing = $fingerprinter->fingerprint(Tier::S);
        self::assertContains('product: 2 vs 1 rows', $unmapped->differences($missing));
        self::assertContains('product.name: 2 vs 1 rows', $unmapped->differences($missing));
        array_pop($this->productIds);
    }

    private function seedTwoProductsInTwoCategories(): void
    {
        $taxId = (string) DeterministicId::create('tax:default');
        $this->registry->getRepository(TaxDefinition::ENTITY_NAME)->upsert([[
            'id' => $taxId,
            'name' => 'DAN 19%',
            'taxRate' => 19.0,
        ]], $this->context);
        $this->categoryIds = [
            (string) DeterministicId::create('test:category:0'),
            (string) DeterministicId::create('test:category:1'),
        ];
        $this->registry->getRepository(CategoryDefinition::ENTITY_NAME)->upsert([
            [
                'id' => $this->categoryIds[0],
                'name' => 'DAN Category 9000',
            ],
            [
                'id' => $this->categoryIds[1],
                'name' => 'DAN Category 9001',
            ],
        ], $this->context);
        $this->productIds = [
            (string) DeterministicId::create('test:fingerprint:0'),
            (string) DeterministicId::create('test:fingerprint:1'),
        ];
        $this->registry->getRepository(ProductDefinition::ENTITY_NAME)->upsert(array_map(fn (int $index): array => [
            'id' => $this->productIds[$index],
            'productNumber' => sprintf('DAN-FP-%08d', $index),
            'name' => sprintf('DAN Fingerprint Product %d', $index),
            'stock' => 10 + $index,
            'taxId' => $taxId,
            'price' => [[
                'currencyId' => Defaults::CURRENCY,
                'gross' => 11.99 + $index,
                'net' => round((11.99 + $index) / 1.19, 2),
                'linked' => false,
            ]],
            'categories' => [['id' => $this->categoryIds[$index]]],
        ], [
            0,
            1,
        ]), $this->context);
    }

    private static function aspect(DatasetFingerprint $fingerprint, string $name): DatasetAspect
    {
        foreach ($fingerprint->aspects as $aspect) {
            if ($aspect->name === $name) {
                return $aspect;
            }
        }
        self::fail(sprintf('Aspect "%s" missing from the fingerprint.', $name));
    }
}
