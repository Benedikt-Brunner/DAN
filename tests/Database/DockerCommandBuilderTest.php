<?php

declare(strict_types=1);

namespace Dan\Harness\Tests\Database;

use Dan\Harness\Database\DatabaseInstance;
use Dan\Harness\Database\DockerCommandBuilder;
use Dan\Harness\Protocol\DatabaseTarget;
use Dan\Harness\Protocol\Engine;
use Dan\Lib\Filesystem\Path;
use Dan\Lib\Time\Duration;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DockerCommandBuilderTest extends TestCase
{
    public function testBuildsDatabaseStartAsAnArgumentVector(): void
    {
        $command = DockerCommandBuilder::startDatabase(
            target: new DatabaseTarget(engine: Engine::MySql, version: '8.4'),
            containerName: 'dan-baseline-small-mysql84',
            hostPort: 33060,
            rootPassword: 'secret with spaces',
        )->withTimeout(Duration::fromSeconds(300))->build();

        self::assertSame([
            'docker',
            'run',
            '--detach',
            '--rm',
            '--name',
            'dan-baseline-small-mysql84',
            '--env',
            'MYSQL_ROOT_PASSWORD=secret with spaces',
            '--env',
            'MYSQL_DATABASE=dan',
            '--env',
            'MARIADB_ROOT_PASSWORD=secret with spaces',
            '--env',
            'MARIADB_DATABASE=dan',
            '--publish',
            '127.0.0.1:33060:3306',
            'mysql:8.4',
        ], $command->arguments);
        self::assertSame(300.0, $command->timeout?->toSecondsFloat());
    }

    public function testImportUsesAFileAsProcessInputWithoutShellRedirection(): void
    {
        $dumpPath = Path::fromString('/tmp/a dump; echo unsafe.sql');
        $command = DockerCommandBuilder::importDatabase(
            instance: $this->instance(),
            dumpPath: $dumpPath,
            rootPassword: 'dan',
        )->build();

        self::assertSame([
            'docker',
            'exec',
            '--interactive',
            '--',
            'dan-test',
            'mariadb',
            '-uroot',
            '-pdan',
            'dan',
        ], $command->arguments);
        self::assertSame($dumpPath, $command->inputPath);
        self::assertNotContains('<', $command->arguments);
    }

    public function testDumpUsesAFileAsProcessOutputWithoutShellRedirection(): void
    {
        $dumpPath = Path::fromString('/tmp/a dump; echo unsafe.sql');
        $command = DockerCommandBuilder::dumpDatabase(
            instance: $this->instance(),
            dumpPath: $dumpPath,
            rootPassword: 'dan',
        )->build();

        self::assertSame($dumpPath, $command->outputPath);
        self::assertNotContains('>', $command->arguments);
        self::assertSame('mariadb-dump', $command->arguments[4]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidContainerNames(): iterable
    {
        yield 'shell expression' => ['dan-$(touch hacked)'];
        yield 'leading option' => ['--privileged'];
        yield 'slash' => ['namespace/container'];
        yield 'too short' => ['d'];
    }

    #[DataProvider('invalidContainerNames')]
    public function testRejectsInvalidContainerNames(string $containerName): void
    {
        $this->expectException(InvalidArgumentException::class);

        DockerCommandBuilder::startDatabase(
            target: new DatabaseTarget(engine: Engine::MySql, version: '8.4'),
            containerName: $containerName,
            hostPort: 33060,
            rootPassword: 'dan',
        );
    }

    public function testRejectsAnImageTagThatCouldBeInterpretedAsArguments(): void
    {
        $this->expectException(InvalidArgumentException::class);

        DockerCommandBuilder::startDatabase(
            target: new DatabaseTarget(engine: Engine::MySql, version: '--privileged'),
            containerName: 'dan-test',
            hostPort: 33060,
            rootPassword: 'dan',
        );
    }

    private function instance(): DatabaseInstance
    {
        return new DatabaseInstance(
            containerName: 'dan-test',
            target: new DatabaseTarget(engine: Engine::MariaDb, version: '11.4'),
            hostPort: 33060,
        );
    }
}
