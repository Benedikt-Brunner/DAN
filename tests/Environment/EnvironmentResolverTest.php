<?php

declare(strict_types=1);

namespace Dan\Harness\Tests\Environment;

use Dan\Harness\Environment\EnvironmentResolver;
use Dan\Harness\Process\ProcessCommand;
use Dan\Harness\Process\ProcessRunner;
use Dan\Harness\Protocol\DatabaseTarget;
use Dan\Harness\Protocol\Engine;
use Dan\Harness\Protocol\Protocol;
use Dan\Lib\Filesystem\Path;
use Dan\Lib\Protocol\Tier;
use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * The resolver against scripted probe outputs and a scripted /proc and /sys:
 * what it discovers is recorded, what it cannot discover is an explicit
 * unknown, and it never pulls anything it already has.
 */
final class EnvironmentResolverTest extends TestCase
{
    private string $workDirectory;

    protected function setUp(): void
    {
        $this->workDirectory = sys_get_temp_dir() . '/dan-environment-' . bin2hex(random_bytes(4));
        mkdir($this->workDirectory . '/dan/src', 0o777, true);
        mkdir($this->workDirectory . '/dan/lib/src', 0o777, true);
        mkdir($this->workDirectory . '/dan/bundle/src', 0o777, true);
        file_put_contents($this->workDirectory . '/dan/src/A.php', 'harness');
        file_put_contents($this->workDirectory . '/dan/lib/src/B.php', 'lib');
        file_put_contents($this->workDirectory . '/dan/bundle/src/C.php', 'probe');
        mkdir($this->workDirectory . '/system', 0o777, true);
    }

    protected function tearDown(): void
    {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->workDirectory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            if ($file instanceof SplFileInfo) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
        }
        rmdir($this->workDirectory);
    }

    public function testRecordsEverythingTheProbesReport(): void
    {
        $this->writeSystemFile(relativePath: 'proc/cpuinfo', content: "processor\t: 0\nmodel name\t: AMD EPYC 7763 64-Core Processor\n");
        $this->writeSystemFile(relativePath: 'sys/fs/cgroup/cpu.max', content: "200000 100000\n");
        $this->writeSystemFile(relativePath: 'sys/fs/cgroup/memory.max', content: "7516192768\n");
        $runner = new ScriptedProcessRunner([
            'composer --version' => "Composer version 2.10.3 2026-08-27 13:34:23\n",
            'docker info' => json_encode([
                'ServerVersion' => '29.5.2',
                'OperatingSystem' => 'Ubuntu 24.04.4 LTS',
                'Architecture' => 'aarch64',
                'NCPU' => 6,
                'MemTotal' => 12_513_599_488,
                'FirewallBackend' => [
                    'Driver' => 'iptables',
                    'Info' => [
                        [
                            'EnableUserlandProxy',
                            'false',
                        ],
                    ],
                ],
            ], \JSON_THROW_ON_ERROR),
            'docker image inspect' => "mysql@sha256:7dcddc01f13bab2f15cde676d44d01f61fc9f99fe7785e86196dfc07d358ae2b\n",
        ]);

        $environment = $this->resolver($runner)->resolve($this->protocol());

        self::assertSame(\PHP_VERSION, $environment->phpVersion);
        self::assertSame('2.10.3', $environment->composerVersion);
        self::assertSame(php_uname('m'), $environment->host->architecture);
        self::assertSame('AMD EPYC 7763 64-Core Processor', $environment->host->cpuModel);
        self::assertSame(2.0, $environment->host->cpuLimit);
        self::assertSame(7_516_192_768, $environment->host->memoryLimitBytes);
        self::assertNotNull($environment->dockerEngine);
        self::assertSame('29.5.2', $environment->dockerEngine->version);
        self::assertSame(6, $environment->dockerEngine->cpus);
        self::assertFalse($environment->dockerEngine->userlandProxy);
        self::assertSame('sha256:7dcddc01f13bab2f15cde676d44d01f61fc9f99fe7785e86196dfc07d358ae2b', $environment->databaseImages[0]->digest);
        self::assertSame('published-port', $environment->databaseNetworkPath->value);
        self::assertNotContains('docker pull', array_map(self::describe(...), $runner->commands), 'A present image is never pulled again.');
    }

    public function testRecordsExplicitUnknownsWhenNothingCanBeDiscovered(): void
    {
        // No probe succeeds, no /proc, no /sys, and the image cannot be pulled.
        $runner = new ScriptedProcessRunner([]);

        $environment = $this->resolver($runner)->resolve($this->protocol());

        self::assertNull($environment->composerVersion);
        self::assertNull($environment->host->cpuLimit);
        self::assertNull($environment->host->memoryLimitBytes);
        self::assertNull($environment->dockerEngine);
        self::assertNull($environment->databaseImages[0]->digest);
        self::assertSame(64, strlen($environment->danRevision));
    }

    public function testPullsAMissingImageOnceToLearnItsDigest(): void
    {
        $runner = new ScriptedProcessRunner([
            'docker pull' => '',
        ]);
        $runner->afterPull = "mysql@sha256:7dcddc01f13bab2f15cde676d44d01f61fc9f99fe7785e86196dfc07d358ae2b\n";

        $environment = $this->resolver($runner)->resolve($this->protocol());

        $commands = array_map(self::describe(...), $runner->commands);
        self::assertSame(1, count(array_filter($commands, fn (string $command): bool => str_starts_with($command, 'docker pull'))));
        self::assertSame('sha256:7dcddc01f13bab2f15cde676d44d01f61fc9f99fe7785e86196dfc07d358ae2b', $environment->databaseImages[0]->digest);
    }

    public function testUnlimitedCgroupsAndOlderEnginesReadAsUnknown(): void
    {
        $this->writeSystemFile(relativePath: 'sys/fs/cgroup/cpu.max', content: "max 100000\n");
        $this->writeSystemFile(relativePath: 'sys/fs/cgroup/memory.max', content: "max\n");
        $runner = new ScriptedProcessRunner([
            'docker info' => json_encode([
                'ServerVersion' => '27.3.1',
                'OperatingSystem' => 'Ubuntu 22.04.5 LTS',
                'Architecture' => 'x86_64',
                'NCPU' => 4,
                'MemTotal' => 16_000_000_000,
            ], \JSON_THROW_ON_ERROR),
        ]);

        $environment = $this->resolver($runner)->resolve($this->protocol());

        self::assertNull($environment->host->cpuLimit);
        self::assertNull($environment->host->memoryLimitBytes);
        self::assertNotNull($environment->dockerEngine);
        self::assertNull($environment->dockerEngine->userlandProxy);
    }

    public function testTheDanRevisionFollowsTheSourceTrees(): void
    {
        $before = $this->resolver(new ScriptedProcessRunner([]))->resolve($this->protocol())->danRevision;
        file_put_contents($this->workDirectory . '/dan/bundle/src/C.php', 'probe changed');

        $after = $this->resolver(new ScriptedProcessRunner([]))->resolve($this->protocol())->danRevision;

        self::assertNotSame($before, $after);
        self::assertSame($after, $this->resolver(new ScriptedProcessRunner([]))->resolve($this->protocol())->danRevision, 'Deterministic for unchanged sources.');
    }

    private function resolver(ProcessRunner $runner): EnvironmentResolver
    {
        return new EnvironmentResolver(
            danRoot: Path::fromString($this->workDirectory . '/dan'),
            processRunner: $runner,
            systemRoot: Path::fromString($this->workDirectory . '/system'),
        );
    }

    private function protocol(): Protocol
    {
        return new Protocol(
            databases: [new DatabaseTarget(engine: Engine::MySql, version: '8.0')],
            tiers: [Tier::S],
            warmupIterations: 1,
            blockWarmupIterations: 1,
            measuredIterations: 3,
            blocks: 1,
            scenarioFilter: null,
        );
    }

    private function writeSystemFile(string $relativePath, string $content): void
    {
        $path = $this->workDirectory . '/system/' . $relativePath;
        if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0o777, true)) {
            throw new RuntimeException(sprintf('Could not create "%s".', dirname($path)));
        }
        file_put_contents($path, $content);
    }

    private static function describe(ProcessCommand $command): string
    {
        return implode(' ', $command->arguments);
    }
}
