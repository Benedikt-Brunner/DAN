<?php

declare(strict_types=1);

namespace Dan\Harness\RunStore\Artifact;

use Dan\Harness\Measurement\Result\SampleCollection;
use RuntimeException;

/**
 * The recorded outcome of one measurement block of a grid cell: the block's
 * identity in the session schedule plus the samples it produced. Blocks are
 * the experimental units of a session - mirrored ordering exists to control
 * host drift - so their membership is preserved rather than flattened away.
 *
 * @phpstan-import-type StatementProfilePayload from StatementProfile
 *
 * @phpstan-type BlockResultPayload array{
 *     blockIndex: int,
 *     executionOrder: int,
 *     warmupIterations: int,
 *     wallNsSamples: list<int>,
 *     statements: list<StatementProfilePayload>
 * }
 */
final class BlockResult
{
    public function __construct(
        public readonly int $blockIndex,
        public readonly int $executionOrder,
        public readonly int $warmupIterations,
        public readonly SampleCollection $wallSamples,
        public readonly StatementProfileCollection $statements,
    ) {}

    /**
     * One wall sample is recorded per measured iteration, so the sample count
     * is the block's iteration count.
     */
    public function iterations(): int
    {
        return count($this->wallSamples);
    }

    /** @return BlockResultPayload */
    public function toArray(): array
    {
        return [
            'blockIndex' => $this->blockIndex,
            'executionOrder' => $this->executionOrder,
            'warmupIterations' => $this->warmupIterations,
            'wallNsSamples' => $this->wallSamples->toNsArray(),
            'statements' => $this->statements->toArray(),
        ];
    }

    /**
     * @param array<mixed> $payload
     */
    public static function fromDecodedArray(array $payload): self
    {
        $blockIndex = $payload['blockIndex'] ?? null;
        $executionOrder = $payload['executionOrder'] ?? null;
        $warmupIterations = $payload['warmupIterations'] ?? null;
        $wallSamples = $payload['wallNsSamples'] ?? null;
        $statements = $payload['statements'] ?? null;
        if (!is_int($blockIndex) || !is_int($executionOrder) || !is_int($warmupIterations) || !is_array($statements)) {
            throw new RuntimeException('Malformed block result payload.');
        }

        return new self(
            blockIndex: $blockIndex,
            executionOrder: $executionOrder,
            warmupIterations: $warmupIterations,
            wallSamples: SampleCollection::fromDecodedArray(payload: $wallSamples, context: 'block wall samples'),
            statements: StatementProfileCollection::fromDecodedArray($statements),
        );
    }
}
