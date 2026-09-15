<?php

declare(strict_types=1);

namespace Dan\Harness\Tests\RunStore;

use Dan\Harness\Environment\DatabaseImage;
use Dan\Harness\Environment\DatabaseNetworkPath;
use Dan\Harness\Environment\DockerEngine;
use Dan\Harness\Environment\ExecutionEnvironment;
use Dan\Harness\Environment\HostMachine;
use Dan\Harness\Implementation\Identity\Identity;
use Dan\Harness\Implementation\Reference\ReferenceType;
use Dan\Harness\Protocol\DatabaseTarget;
use Dan\Harness\Protocol\Engine;
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
                blockWarmupIterations: 2,
                measuredIterations: 30,
                blocks: 4,
                scenarioFilter: null,
            ),
            environment: new ExecutionEnvironment(
                danRevision: str_repeat('d', 64),
                phpVersion: '8.4.24',
                composerVersion: '2.10.3',
                host: new HostMachine(operatingSystem: 'Linux 6.8.0-1021-azure', architecture: 'x86_64', cpuModel: 'AMD EPYC 7763 64-Core Processor', cpuLimit: null, memoryLimitBytes: null),
                dockerEngine: new DockerEngine(version: '29.5.2', operatingSystem: 'Ubuntu 24.04.4 LTS', architecture: 'x86_64', cpus: 4, memoryBytes: 16_775_622_656, userlandProxy: false),
                databaseImages: [new DatabaseImage(target: new DatabaseTarget(engine: Engine::MySql, version: '8.0'), digest: 'sha256:7dcddc01f13bab2f15cde676d44d01f61fc9f99fe7785e86196dfc07d358ae2b')],
                databaseNetworkPath: DatabaseNetworkPath::PublishedPort,
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
