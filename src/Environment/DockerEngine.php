<?php

declare(strict_types=1);

namespace Dan\Harness\Environment;

use RuntimeException;

/**
 * The Docker engine that runs the database containers - on macOS a VM with
 * its own OS, CPU count and memory, which is why it is recorded separately
 * from the host machine. userlandProxy is the engine's published-port
 * forwarding mode (docker-proxy vs kernel NAT); engines that do not report
 * it leave it null.
 *
 * @phpstan-type DockerEnginePayload array{
 *     version: string,
 *     operatingSystem: string,
 *     architecture: string,
 *     cpus: int,
 *     memoryBytes: int,
 *     userlandProxy: bool|null
 * }
 */
final class DockerEngine
{
    public function __construct(
        public readonly string $version,
        public readonly string $operatingSystem,
        public readonly string $architecture,
        public readonly int $cpus,
        public readonly int $memoryBytes,
        public readonly ?bool $userlandProxy,
    ) {}

    /** @return DockerEnginePayload */
    public function toArray(): array
    {
        return [
            'version' => $this->version,
            'operatingSystem' => $this->operatingSystem,
            'architecture' => $this->architecture,
            'cpus' => $this->cpus,
            'memoryBytes' => $this->memoryBytes,
            'userlandProxy' => $this->userlandProxy,
        ];
    }

    /**
     * @param array<mixed> $payload
     */
    public static function fromDecodedArray(array $payload): self
    {
        $version = $payload['version'] ?? null;
        $operatingSystem = $payload['operatingSystem'] ?? null;
        $architecture = $payload['architecture'] ?? null;
        $cpus = $payload['cpus'] ?? null;
        $memoryBytes = $payload['memoryBytes'] ?? null;
        $userlandProxy = $payload['userlandProxy'] ?? null;
        if (
            !is_string($version)
            || !is_string($operatingSystem)
            || !is_string($architecture)
            || !is_int($cpus)
            || !is_int($memoryBytes)
            || ($userlandProxy !== null && !is_bool($userlandProxy))
        ) {
            throw new RuntimeException('Malformed Docker engine payload.');
        }

        return new self(
            version: $version,
            operatingSystem: $operatingSystem,
            architecture: $architecture,
            cpus: $cpus,
            memoryBytes: $memoryBytes,
            userlandProxy: $userlandProxy,
        );
    }
}
