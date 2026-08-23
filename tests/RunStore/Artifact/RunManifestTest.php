<?php

declare(strict_types=1);

namespace Dan\Harness\Tests\RunStore;

use Dan\Harness\Implementation\Identity\Identity;
use Dan\Harness\Implementation\Reference\ReferenceType;
use Dan\Harness\Protocol\Protocol;
use Dan\Harness\RunStore\Artifact\RunManifest;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class RunManifestTest extends TestCase
{
    public function testImplementationIsSerializedUsingDomainVocabulary(): void
    {
        $manifest = new RunManifest(
            runId: 'run-1',
            createdAt: new DateTimeImmutable('2026-08-23T12:00:00+00:00'),
            implementationReferenceType: ReferenceType::Release,
            implementationReference: 'v6.7.0.0',
            implementationIdentity: new Identity(id: 'v6.7.0.0', label: 'shopware/core v6.7.0.0'),
            protocol: new Protocol(
                databases: [],
                tiers: [],
                warmupIterations: 5,
                measuredIterations: 30,
                blocks: 4,
                scenarioFilter: null,
            ),
        );

        $payload = $manifest->toArray();

        self::assertSame([
            'reference' => [
                'type' => 'release',
                'value' => 'v6.7.0.0',
            ],
            'identity' => [
                'id' => 'v6.7.0.0',
                'label' => 'shopware/core v6.7.0.0',
            ],
        ], $payload['implementation']);
        self::assertArrayNotHasKey('dal', $payload);

        $decoded = RunManifest::fromDecodedArray($payload);
        self::assertSame($payload, $decoded->toArray());
    }
}
