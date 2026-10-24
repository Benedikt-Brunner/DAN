<?php

declare(strict_types=1);

namespace Dan\Harness\Environment;

use RuntimeException;

/**
 * The machine the harness and the probe processes run on. Resource limits
 * are the effective cgroup limits where the platform exposes them; null
 * means "not discoverable here", not "unlimited".
 *
 * @phpstan-type HostMachinePayload array{
 *     operatingSystem: string,
 *     architecture: string,
 *     cpuModel: string|null,
 *     cpuLimit: float|null,
 *     memoryLimitBytes: int|null
 * }
 */
final class HostMachine
{
    public function __construct(
        public readonly string $operatingSystem,
        public readonly string $architecture,
        public readonly ?string $cpuModel,
        public readonly ?float $cpuLimit,
        public readonly ?int $memoryLimitBytes,
    ) {}

    /** @return HostMachinePayload */
    public function toArray(): array
    {
        return [
            'operatingSystem' => $this->operatingSystem,
            'architecture' => $this->architecture,
            'cpuModel' => $this->cpuModel,
            'cpuLimit' => $this->cpuLimit,
            'memoryLimitBytes' => $this->memoryLimitBytes,
        ];
    }

    /**
     * @param array<mixed> $payload
     */
    public static function fromDecodedArray(array $payload): self
    {
        $operatingSystem = $payload['operatingSystem'] ?? null;
        $architecture = $payload['architecture'] ?? null;
        $cpuModel = $payload['cpuModel'] ?? null;
        $cpuLimit = $payload['cpuLimit'] ?? null;
        $memoryLimitBytes = $payload['memoryLimitBytes'] ?? null;
        if (
            !is_string($operatingSystem)
            || !is_string($architecture)
            || ($cpuModel !== null && !is_string($cpuModel))
            || ($cpuLimit !== null && !is_float($cpuLimit) && !is_int($cpuLimit))
            || ($memoryLimitBytes !== null && !is_int($memoryLimitBytes))
        ) {
            throw new RuntimeException('Malformed host machine payload.');
        }

        return new self(
            operatingSystem: $operatingSystem,
            architecture: $architecture,
            cpuModel: $cpuModel,
            cpuLimit: $cpuLimit === null ? null : (float) $cpuLimit,
            memoryLimitBytes: $memoryLimitBytes,
        );
    }
}
