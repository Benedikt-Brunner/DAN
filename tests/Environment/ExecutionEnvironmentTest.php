<?php

declare(strict_types=1);

namespace Dan\Harness\Tests\Environment;

use Dan\Harness\Environment\DatabaseImage;
use Dan\Harness\Environment\DatabaseNetworkPath;
use Dan\Harness\Environment\DockerEngine;
use Dan\Harness\Environment\ExecutionEnvironment;
use Dan\Harness\Environment\HostMachine;
use Dan\Harness\Protocol\DatabaseTarget;
use Dan\Harness\Protocol\Engine;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ExecutionEnvironmentTest extends TestCase
{
    public function testRoundTripsWithUnknownsKeptExplicit(): void
    {
        $environment = self::environment(dockerEngine: null, digest: null, composerVersion: null);

        $payload = $environment->toArray();

        self::assertNull($payload['composerVersion']);
        self::assertNull($payload['dockerEngine']);
        self::assertNull($payload['databaseImages'][0]['digest']);
        self::assertSame('published-port', $payload['databaseNetworkPath']);
        self::assertSame($payload, ExecutionEnvironment::fromDecodedArray($payload)->toArray());
    }

    public function testRoundTripsWithEverythingKnown(): void
    {
        $environment = self::environment();

        $payload = $environment->toArray();

        self::assertSame($payload, ExecutionEnvironment::fromDecodedArray($payload)->toArray());
        self::assertTrue(ExecutionEnvironment::fromDecodedArray($payload)->comparableTo($environment));
    }

    public function testComparabilityRequiresTheSameDanRevision(): void
    {
        self::assertFalse(self::environment()->comparableTo(self::environment(danRevision: str_repeat('b', 64))));
    }

    public function testComparabilityRequiresTheSameImageContent(): void
    {
        $a = self::environment(digest: 'sha256:' . str_repeat('1', 64));
        $b = self::environment(digest: 'sha256:' . str_repeat('2', 64));

        self::assertFalse($a->comparableTo($b));
        // The tag moved but the digest did not: still the same image.
        self::assertTrue($a->comparableTo(self::environment(digest: 'sha256:' . str_repeat('1', 64), phpVersion: '8.5.0')));
    }

    public function testAnUnknownDigestOnlyAgreesWithAnUnknownDigest(): void
    {
        self::assertFalse(self::environment(digest: null)->comparableTo(self::environment()));
        self::assertTrue(self::environment(digest: null)->comparableTo(self::environment(digest: null)));
    }

    public function testAMissingTargetOnTheOtherSideBreaksComparability(): void
    {
        $other = new ExecutionEnvironment(
            danRevision: str_repeat('a', 64),
            phpVersion: '8.4.24',
            composerVersion: null,
            host: self::host(),
            dockerEngine: null,
            databaseImages: [],
            databaseNetworkPath: DatabaseNetworkPath::PublishedPort,
        );

        self::assertFalse(self::environment()->comparableTo($other));
        self::assertTrue($other->comparableTo(self::environment()), 'No images on this side means nothing to disagree about.');
    }

    public function testLatencyConditionsAreInformationalNotComparabilityCriteria(): void
    {
        $engine = new DockerEngine(version: '28.0.1', operatingSystem: 'Ubuntu 22.04', architecture: 'x86_64', cpus: 2, memoryBytes: 7_000_000_000, userlandProxy: true);

        self::assertTrue(self::environment()->comparableTo(self::environment(dockerEngine: $engine, phpVersion: '8.5.0', composerVersion: '2.8.0')));
    }

    public function testRefusesAnUnknownNetworkPath(): void
    {
        $payload = self::environment()->toArray();
        $payload['databaseNetworkPath'] = 'host';

        $this->expectException(RuntimeException::class);
        ExecutionEnvironment::fromDecodedArray($payload);
    }

    public function testRefusesAMalformedDockerEngine(): void
    {
        $payload = self::environment()->toArray();
        $payload['dockerEngine'] = ['version' => 29];

        $this->expectException(RuntimeException::class);
        ExecutionEnvironment::fromDecodedArray($payload);
    }

    private static function environment(
        string $danRevision = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
        string $phpVersion = '8.4.24',
        ?string $composerVersion = '2.10.3',
        ?DockerEngine $dockerEngine = new DockerEngine(version: '29.5.2', operatingSystem: 'Ubuntu 24.04.4 LTS', architecture: 'aarch64', cpus: 6, memoryBytes: 12_513_599_488, userlandProxy: false),
        ?string $digest = 'sha256:7dcddc01f13bab2f15cde676d44d01f61fc9f99fe7785e86196dfc07d358ae2b',
    ): ExecutionEnvironment {
        return new ExecutionEnvironment(
            danRevision: $danRevision,
            phpVersion: $phpVersion,
            composerVersion: $composerVersion,
            host: self::host(),
            dockerEngine: $dockerEngine,
            databaseImages: [new DatabaseImage(target: new DatabaseTarget(engine: Engine::MySql, version: '8.0'), digest: $digest)],
            databaseNetworkPath: DatabaseNetworkPath::PublishedPort,
        );
    }

    private static function host(): HostMachine
    {
        return new HostMachine(operatingSystem: 'Linux 6.8.0', architecture: 'x86_64', cpuModel: 'AMD EPYC 7763', cpuLimit: 2.0, memoryLimitBytes: 7_516_192_768);
    }
}
