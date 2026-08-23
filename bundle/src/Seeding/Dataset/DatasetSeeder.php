<?php

declare(strict_types=1);

namespace Dan\Probe\Seeding\Dataset;

use Dan\Probe\Seeding\Progress\SeedProgressReporter;
use Dan\Probe\Synthetic\SyntheticBlobDefinition;
use Dan\Probe\Synthetic\SyntheticSchemaInstaller;
use Shopware\Core\Content\Category\CategoryDefinition;
use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\DefinitionInstanceRegistry;
use Shopware\Core\System\Tax\TaxDefinition;

/**
 * Writes a deterministic dataset through the public DAL API. Stable ids and
 * upserts make every operation safe to resume after an interruption.
 *
 * @api Symfony service instantiated by the dependency-injection container.
 */
final readonly class DatasetSeeder
{
    private const CHUNK_SIZE = 500;

    public function __construct(
        private DefinitionInstanceRegistry $definitionRegistry,
        private SyntheticSchemaInstaller $syntheticSchemaInstaller,
    ) {}

    public function seed(TierSpec $spec, Context $context, SeedProgressReporter $progress): void
    {
        $this->syntheticSchemaInstaller->install();
        $taxId = $this->seedTax($context);

        $progress->seedingCategories($spec->categories);
        $this->seedCategories(spec: $spec, context: $context);

        $progress->seedingProducts($spec->products);
        $this->seedProducts(spec: $spec, taxId: $taxId, context: $context, progress: $progress);

        $progress->seedingSyntheticBlobs($spec->syntheticBlobs);
        $this->seedSyntheticBlobs(spec: $spec, context: $context, progress: $progress);
        $progress->finished();
    }

    private function seedTax(Context $context): string
    {
        $taxId = (string) DeterministicId::create('tax:default');
        $this->definitionRegistry->getRepository(TaxDefinition::ENTITY_NAME)->upsert([
            [
                'id' => $taxId,
                'name' => 'DAN 19%',
                'taxRate' => 19.0,
            ],
        ], $context);

        return $taxId;
    }

    private function seedCategories(TierSpec $spec, Context $context): void
    {
        $repository = $this->definitionRegistry->getRepository(CategoryDefinition::ENTITY_NAME);
        foreach ($this->indexChunks($spec->categories) as $chunk) {
            $repository->upsert(array_map(fn (int $index): array => [
                'id' => (string) DeterministicId::create('category:' . $index),
                'name' => sprintf('DAN Category %04d', $index),
            ], $chunk), $context);
        }
    }

    private function seedProducts(
        TierSpec $spec,
        string $taxId,
        Context $context,
        SeedProgressReporter $progress,
    ): void {
        $repository = $this->definitionRegistry->getRepository(ProductDefinition::ENTITY_NAME);
        foreach ($this->indexChunks($spec->products) as $chunkIndex => $chunk) {
            $repository->upsert(
                array_map(
                    fn (int $index): array => $this->productPayload(index: $index, spec: $spec, taxId: $taxId),
                    $chunk,
                ),
                $context,
            );

            if (($chunkIndex + 1) % 20 === 0) {
                $progress->productsSeeded(
                    seeded: ($chunkIndex + 1) * self::CHUNK_SIZE,
                    total: $spec->products,
                );
            }
        }
    }

    /**
     * @return array{
     *     id: string,
     *     productNumber: string,
     *     name: string,
     *     stock: int,
     *     taxId: string,
     *     price: list<array{currencyId: string, gross: float, net: float, linked: false}>,
     *     categories: list<array{id: string}>
     * }
     */
    private function productPayload(int $index, TierSpec $spec, string $taxId): array
    {
        $gross = 10.0 + ($index % 990) + 0.99;

        return [
            'id' => (string) DeterministicId::create('product:' . $index),
            'productNumber' => sprintf('DAN-%08d', $index),
            'name' => sprintf('DAN Product %08d', $index),
            'stock' => $index % 1_000,
            'taxId' => $taxId,
            'price' => [[
                'currencyId' => Defaults::CURRENCY,
                'gross' => $gross,
                'net' => round($gross / 1.19, 2),
                'linked' => false,
            ]],
            'categories' => [
                ['id' => (string) DeterministicId::create('category:' . ($index % $spec->categories))],
            ],
        ];
    }

    private function seedSyntheticBlobs(
        TierSpec $spec,
        Context $context,
        SeedProgressReporter $progress,
    ): void {
        $repository = $this->definitionRegistry->getRepository(SyntheticBlobDefinition::ENTITY_NAME);
        foreach ($this->indexChunks($spec->syntheticBlobs) as $chunkIndex => $chunk) {
            $repository->upsert(
                array_map(
                    fn (int $index): array => self::syntheticBlobPayload($index),
                    $chunk,
                ),
                $context,
            );

            if (($chunkIndex + 1) % 20 === 0) {
                $progress->syntheticBlobsSeeded(
                    seeded: ($chunkIndex + 1) * self::CHUNK_SIZE,
                    total: $spec->syntheticBlobs,
                );
            }
        }
    }

    /**
     * @return array{
     *     id: string,
     *     name: string,
     *     payload: array{segment: string, score: int, active: bool},
     *     rank: int
     * }
     */
    private static function syntheticBlobPayload(int $index): array
    {
        return [
            'id' => (string) DeterministicId::create('synthetic-blob:' . $index),
            'name' => sprintf('DAN Synthetic Blob %08d', $index),
            'payload' => [
                'segment' => sprintf('segment-%02d', $index % 16),
                'score' => $index % 1_000,
                'active' => $index % 3 === 0,
            ],
            'rank' => $index,
        ];
    }

    /** @return list<list<int>> */
    private function indexChunks(int $total): array
    {
        return array_chunk(range(0, $total - 1), self::CHUNK_SIZE);
    }
}
