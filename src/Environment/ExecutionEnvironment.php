<?php

declare(strict_types=1);

namespace Dan\Harness\Environment;

use RuntimeException;

/**
 * Where and with what a run was measured: enough for an outsider to
 * reproduce or audit the claim without asking the person who ran it. Stable
 * facts only - no timestamps, no paths, no secrets - and every fact that
 * could not be discovered is an explicit null, never a guess.
 *
 * Within a session both runs share the environment. Across stored profiles
 * the fields split in two: those that can change what SQL is generated or
 * how it is compared (DAN's own revision, the exact database images) must
 * match for a comparison to mean anything; the rest describe latency
 * conditions and are informational, because latency is never compared
 * across sessions anyway.
 *
 * @phpstan-import-type HostMachinePayload from HostMachine
 * @phpstan-import-type DockerEnginePayload from DockerEngine
 * @phpstan-import-type DatabaseImagePayload from DatabaseImage
 *
 * @phpstan-type ExecutionEnvironmentPayload array{
 *     danRevision: string,
 *     phpVersion: string,
 *     composerVersion: string|null,
 *     host: HostMachinePayload,
 *     dockerEngine: DockerEnginePayload|null,
 *     databaseImages: list<DatabaseImagePayload>,
 *     databaseNetworkPath: string
 * }
 */
final class ExecutionEnvironment
{
    /**
     * @param list<DatabaseImage> $databaseImages
     */
    public function __construct(
        public readonly string $danRevision,
        public readonly string $phpVersion,
        public readonly ?string $composerVersion,
        public readonly HostMachine $host,
        public readonly ?DockerEngine $dockerEngine,
        public readonly array $databaseImages,
        public readonly DatabaseNetworkPath $databaseNetworkPath,
    ) {}

    /**
     * Stored profiles recorded under these conditions may be compared for
     * SQL and structure: the same DAN, and every database target resolved
     * to the same image content (unknown digests count as agreeing only
     * with unknown digests).
     */
    public function comparableTo(self $other): bool
    {
        if ($this->danRevision !== $other->danRevision) {
            return false;
        }
        $otherDigests = [];
        foreach ($other->databaseImages as $image) {
            $otherDigests[$image->target->id()] = $image->digest;
        }
        foreach ($this->databaseImages as $image) {
            if (!array_key_exists($image->target->id(), $otherDigests) || $otherDigests[$image->target->id()] !== $image->digest) {
                return false;
            }
        }

        return true;
    }

    /** @return ExecutionEnvironmentPayload */
    public function toArray(): array
    {
        return [
            'danRevision' => $this->danRevision,
            'phpVersion' => $this->phpVersion,
            'composerVersion' => $this->composerVersion,
            'host' => $this->host->toArray(),
            'dockerEngine' => $this->dockerEngine?->toArray(),
            'databaseImages' => array_map(fn (DatabaseImage $image): array => $image->toArray(), $this->databaseImages),
            'databaseNetworkPath' => $this->databaseNetworkPath->value,
        ];
    }

    /**
     * @param array<mixed> $payload
     */
    public static function fromDecodedArray(array $payload): self
    {
        $danRevision = $payload['danRevision'] ?? null;
        $phpVersion = $payload['phpVersion'] ?? null;
        $composerVersion = $payload['composerVersion'] ?? null;
        $host = $payload['host'] ?? null;
        $dockerEngine = $payload['dockerEngine'] ?? null;
        $databaseImages = $payload['databaseImages'] ?? null;
        $databaseNetworkPath = $payload['databaseNetworkPath'] ?? null;
        if (
            !is_string($danRevision)
            || !is_string($phpVersion)
            || ($composerVersion !== null && !is_string($composerVersion))
            || !is_array($host)
            || ($dockerEngine !== null && !is_array($dockerEngine))
            || !is_array($databaseImages)
            || !array_is_list($databaseImages)
            || !is_string($databaseNetworkPath)
        ) {
            throw new RuntimeException('Malformed execution environment payload.');
        }
        $images = [];
        foreach ($databaseImages as $image) {
            if (!is_array($image)) {
                throw new RuntimeException('Malformed execution environment: database images must be objects.');
            }
            $images[] = DatabaseImage::fromDecodedArray($image);
        }

        return new self(
            danRevision: $danRevision,
            phpVersion: $phpVersion,
            composerVersion: $composerVersion,
            host: HostMachine::fromDecodedArray($host),
            dockerEngine: $dockerEngine === null ? null : DockerEngine::fromDecodedArray($dockerEngine),
            databaseImages: $images,
            databaseNetworkPath: DatabaseNetworkPath::tryFrom($databaseNetworkPath) ?? throw new RuntimeException(sprintf('Malformed execution environment: unknown database network path "%s".', $databaseNetworkPath)),
        );
    }
}
