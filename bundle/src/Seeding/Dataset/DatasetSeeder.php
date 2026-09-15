<?php

declare(strict_types=1);

namespace Dan\Probe\Seeding\Dataset;

use Dan\Probe\Seeding\Progress\SeedProgressReporter;
use Dan\Probe\Synthetic\SyntheticBlobDefinition;
use Dan\Probe\Synthetic\SyntheticSchemaInstaller;
use RuntimeException;
use Shopware\Core\Content\Category\CategoryDefinition;
use Shopware\Core\Content\Media\MediaDefinition;
use Shopware\Core\Content\Product\Aggregate\ProductReview\ProductReviewDefinition;
use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\DefinitionInstanceRegistry;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\System\SalesChannel\SalesChannelDefinition;
use Shopware\Core\System\Tax\TaxDefinition;

/**
 * Writes a deterministic dataset through the public DAL API. Stable ids and
 * upserts make every operation safe to resume after an interruption. The
 * rules relating rows to each other live in TierSpec, which the corpus
 * scenarios read their expected cardinalities from.
 *
 * @api Symfony service instantiated by the dependency-injection container.
 */
final readonly class DatasetSeeder
{
    private const CHUNK_SIZE = 500;
    private const PROGRESS_EVERY_CHUNKS = 20;

    public function __construct(
        private DefinitionInstanceRegistry $definitionRegistry,
        private SyntheticSchemaInstaller $syntheticSchemaInstaller,
    ) {}

    public function seed(TierSpec $spec, Context $context, SeedProgressReporter $progress): void
    {
        $this->syntheticSchemaInstaller->install();
        $taxId = $this->seedTax($context);
        $salesChannelId = $this->storefrontSalesChannelId($context);

        $progress->seeding(what: 'categories', total: $spec->categories);
        $this->seedCategories(spec: $spec, context: $context);

        $progress->seeding(what: 'products', total: $spec->products);
        $this->seedProducts(spec: $spec, taxId: $taxId, context: $context, progress: $progress);

        $progress->seeding(what: 'product variants', total: $spec->variants());
        $this->seedVariants(spec: $spec, context: $context, progress: $progress);

        $progress->seeding(what: 'product reviews', total: $spec->reviews);
        $this->seedReviews(spec: $spec, salesChannelId: $salesChannelId, context: $context, progress: $progress);

        $progress->seeding(what: 'media', total: $spec->media);
        $this->seedMedia(spec: $spec, context: $context, progress: $progress);

        $progress->seeding(what: 'synthetic blobs', total: $spec->syntheticBlobs);
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

    /**
     * Reviews require a sales channel. Its id differs between installations
     * (which is why no fingerprint includes it); the storefront channel the
     * installer creates is looked up, and any channel serves as fallback.
     */
    private function storefrontSalesChannelId(Context $context): string
    {
        $repository = $this->definitionRegistry->getRepository(SalesChannelDefinition::ENTITY_NAME);
        $storefront = new Criteria();
        $storefront->addFilter(new EqualsFilter('typeId', Defaults::SALES_CHANNEL_TYPE_STOREFRONT));
        $storefront->addSorting(new FieldSorting('id', FieldSorting::ASCENDING));
        $storefront->setLimit(1);
        $id = $repository->searchIds($storefront, $context)->firstId();
        if ($id === null) {
            $any = new Criteria();
            $any->addSorting(new FieldSorting('id', FieldSorting::ASCENDING));
            $any->setLimit(1);
            $id = $repository->searchIds($any, $context)->firstId();
        }

        return $id ?? throw new RuntimeException('The runtime has no sales channel - product reviews cannot be seeded without one.');
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

    private function seedProducts(TierSpec $spec, string $taxId, Context $context, SeedProgressReporter $progress): void
    {
        $repository = $this->definitionRegistry->getRepository(ProductDefinition::ENTITY_NAME);
        foreach ($this->indexChunks($spec->products) as $chunkIndex => $chunk) {
            $repository->upsert(
                array_map(
                    fn (int $index): array => $this->productPayload(index: $index, spec: $spec, taxId: $taxId),
                    $chunk,
                ),
                $context,
            );
            $this->reportChunk(progress: $progress, chunkIndex: $chunkIndex, total: $spec->products);
        }
    }

    /**
     * @return array{
     *     id: string,
     *     productNumber: string,
     *     ean: string,
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
            'ean' => sprintf('DANEAN%08d', $index),
            'name' => sprintf('DAN Product %08d', $index),
            'stock' => $spec->productStock($index),
            'taxId' => $taxId,
            'price' => [[
                'currencyId' => Defaults::CURRENCY,
                'gross' => $gross,
                'net' => round($gross / 1.19, 2),
                'linked' => false,
            ]],
            'categories' => [
                ['id' => (string) DeterministicId::create('category:' . $spec->productCategory($index))],
            ],
        ];
    }

    /**
     * One child per variant parent. Children inherit name, tax, price and
     * categories through the DAL's inheritance and carry their own product
     * number and EAN - the shape inheritance-aware SQL has to resolve.
     */
    private function seedVariants(TierSpec $spec, Context $context, SeedProgressReporter $progress): void
    {
        $repository = $this->definitionRegistry->getRepository(ProductDefinition::ENTITY_NAME);
        $parents = array_values(array_filter(range(0, $spec->products - 1), TierSpec::hasVariant(...)));
        foreach (array_chunk($parents, self::CHUNK_SIZE) as $chunkIndex => $chunk) {
            $repository->upsert(array_map(fn (int $parent): array => [
                'id' => (string) DeterministicId::create('product:variant:' . $parent),
                'parentId' => (string) DeterministicId::create('product:' . $parent),
                'productNumber' => sprintf('DAN-%08d-V1', $parent),
                'ean' => sprintf('DANEAN%08d-V1', $parent),
                'stock' => $spec->productStock($parent),
            ], $chunk), $context);
            $this->reportChunk(progress: $progress, chunkIndex: $chunkIndex, total: count($parents));
        }
    }

    private function seedReviews(TierSpec $spec, string $salesChannelId, Context $context, SeedProgressReporter $progress): void
    {
        $repository = $this->definitionRegistry->getRepository(ProductReviewDefinition::ENTITY_NAME);
        foreach ($this->indexChunks($spec->reviews) as $chunkIndex => $chunk) {
            $repository->upsert(array_map(fn (int $index): array => [
                'id' => (string) DeterministicId::create('review:' . $index),
                'productId' => (string) DeterministicId::create('product:' . $spec->reviewProduct($index)),
                'salesChannelId' => $salesChannelId,
                'languageId' => Defaults::LANGUAGE_SYSTEM,
                'title' => sprintf('DAN Review %08d', $index),
                'content' => 'Deterministic review content written by the DAN seeder.',
                'points' => (float) TierSpec::reviewPoints($index),
                'status' => $index % 3 === 0,
            ], $chunk), $context);
            $this->reportChunk(progress: $progress, chunkIndex: $chunkIndex, total: $spec->reviews);
        }
    }

    private function seedMedia(TierSpec $spec, Context $context, SeedProgressReporter $progress): void
    {
        $repository = $this->definitionRegistry->getRepository(MediaDefinition::ENTITY_NAME);
        foreach ($this->indexChunks($spec->media) as $chunkIndex => $chunk) {
            $repository->upsert(array_map(fn (int $index): array => [
                'id' => (string) DeterministicId::create('media:' . $index),
                'fileName' => sprintf('dan-media-%08d', $index),
                'fileExtension' => TierSpec::mediaExtension($index),
                'mimeType' => self::mimeType(TierSpec::mediaExtension($index)),
                'fileSize' => 1_024 + $index,
                'private' => false,
                'title' => sprintf('DAN Media %08d', $index),
            ], $chunk), $context);
            $this->reportChunk(progress: $progress, chunkIndex: $chunkIndex, total: $spec->media);
        }
    }

    private static function mimeType(string $extension): string
    {
        return match ($extension) {
            'pdf' => 'application/pdf',
            'jpg' => 'image/jpeg',
            default => 'image/' . $extension,
        };
    }

    private function seedSyntheticBlobs(TierSpec $spec, Context $context, SeedProgressReporter $progress): void
    {
        $repository = $this->definitionRegistry->getRepository(SyntheticBlobDefinition::ENTITY_NAME);
        foreach ($this->indexChunks($spec->syntheticBlobs) as $chunkIndex => $chunk) {
            $repository->upsert(array_map(self::syntheticBlobPayload(...), $chunk), $context);
            $this->reportChunk(progress: $progress, chunkIndex: $chunkIndex, total: $spec->syntheticBlobs);
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
                'segment' => TierSpec::blobSegment($index),
                'score' => TierSpec::blobScore($index),
                'active' => TierSpec::blobActive($index),
            ],
            'rank' => $index,
        ];
    }

    private function reportChunk(SeedProgressReporter $progress, int $chunkIndex, int $total): void
    {
        if (($chunkIndex + 1) % self::PROGRESS_EVERY_CHUNKS === 0) {
            $progress->seeded(seeded: min(($chunkIndex + 1) * self::CHUNK_SIZE, $total), total: $total);
        }
    }

    /** @return list<list<int>> */
    private function indexChunks(int $total): array
    {
        return $total < 1 ? [] : array_chunk(range(0, $total - 1), self::CHUNK_SIZE);
    }
}
