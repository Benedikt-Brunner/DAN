<?php

declare(strict_types=1);

namespace Dan\Harness\RunStore\Artifact;

use Dan\Harness\Implementation\Identity\Identity;
use Dan\Harness\Implementation\Reference\ReferenceType;
use Dan\Harness\Protocol\Protocol;
use DateTimeImmutable;
use DateTimeInterface;
use RuntimeException;

/**
 * @phpstan-import-type IdentityPayload from Identity
 * @phpstan-import-type ProtocolPayload from Protocol
 *
 * @phpstan-type RunManifestPayload array{
 *     schemaVersion: int,
 *     runId: string,
 *     createdAt: string,
 *     implementation: array{
 *         reference: array{
 *             type: string,
 *             value: string
 *         },
 *         identity: IdentityPayload
 *     },
 *     protocol: ProtocolPayload
 * }
 */
final class RunManifest
{
    public const SCHEMA_VERSION = 1;

    public function __construct(
        public readonly string $runId,
        public readonly DateTimeImmutable $createdAt,
        public readonly ReferenceType $implementationReferenceType,
        public readonly string $implementationReference,
        public readonly Identity $implementationIdentity,
        public readonly Protocol $protocol,
    ) {}

    /** @return RunManifestPayload */
    public function toArray(): array
    {
        return [
            'schemaVersion' => self::SCHEMA_VERSION,
            'runId' => $this->runId,
            'createdAt' => $this->createdAt->format(DateTimeInterface::ATOM),
            'implementation' => [
                'reference' => [
                    'type' => $this->implementationReferenceType->value,
                    'value' => $this->implementationReference,
                ],
                'identity' => $this->implementationIdentity->toArray(),
            ],
            'protocol' => $this->protocol->toArray(),
        ];
    }

    /**
     * @param array<mixed> $payload
     */
    public static function fromDecodedArray(array $payload): self
    {
        $schemaVersion = $payload['schemaVersion'] ?? null;
        $runId = $payload['runId'] ?? null;
        $createdAt = $payload['createdAt'] ?? null;
        $implementation = $payload['implementation'] ?? null;
        $protocol = $payload['protocol'] ?? null;
        if (!is_int($schemaVersion) || !is_string($runId) || !is_string($createdAt) || !is_array($implementation) || !is_array($protocol)) {
            throw new RuntimeException('Malformed run-manifest payload.');
        }
        if ($schemaVersion !== self::SCHEMA_VERSION) {
            throw new RuntimeException(sprintf('Unsupported run-manifest schema version %d (expected %d).', $schemaVersion, self::SCHEMA_VERSION));
        }

        $reference = $implementation['reference'] ?? null;
        $identity = $implementation['identity'] ?? null;
        if (!is_array($reference) || !is_array($identity)) {
            throw new RuntimeException('Malformed run manifest: implementation payload is invalid.');
        }

        $referenceType = $reference['type'] ?? null;
        $referenceValue = $reference['value'] ?? null;
        if (!is_string($referenceType) || !is_string($referenceValue)) {
            throw new RuntimeException('Malformed run manifest: implementation reference is invalid.');
        }

        return new self(
            runId: $runId,
            createdAt: new DateTimeImmutable($createdAt),
            implementationReferenceType: ReferenceType::tryFrom($referenceType) ?? throw new RuntimeException(sprintf('Malformed run manifest: unknown implementation reference type "%s".', $referenceType)),
            implementationReference: $referenceValue,
            implementationIdentity: Identity::fromDecodedArray($identity),
            protocol: Protocol::fromDecodedArray($protocol),
        );
    }
}
