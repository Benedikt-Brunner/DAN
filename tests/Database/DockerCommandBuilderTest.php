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
    public function testBuildsDatabaseStartAsAnArgumentVectorOnTheDataVolume(): void
    {
        $command = DockerCommandBuilder::startDatabase(
            instance: new DatabaseInstance(
                containerName: 'dan-baseline-small-mysql84',
                target: new DatabaseTarget(engine: Engine::MySql, version: '8.4'),
                hostPort: 33060,
            ),
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
            '--volume',
            'dan-baseline-small-mysql84-data:/var/lib/mysql',
            '--publish',
            '127.0.0.1:33060:3306',
            'mysql:8.4',
        ], $command->arguments);
        self::assertSame(300.0, $command->timeout?->toSecondsFloat());
    }

    public function testStopGivesTheServerTimeToShutDownCleanly(): void
    {
        $command = DockerCommandBuilder::stopDatabase(instance: $this->instance(), gracePeriod: Duration::fromSeconds(300))->build();

        self::assertSame([
            'docker',
            'stop',
            '--time',
            '300',
            '--',
            'dan-test',
        ], $command->arguments);
    }

    public function testTheDataVolumeIsNamedAfterTheContainer(): void
    {
        self::assertSame([
            'docker',
            'volume',
            'create',
            '--',
            'dan-test-data',
        ], DockerCommandBuilder::createVolume($this->instance())->build()->arguments);
        self::assertSame([
            'docker',
            'volume',
            'rm',
            '--force',
            '--',
            'dan-test-data',
        ], DockerCommandBuilder::removeVolume($this->instance())->build()->arguments);
    }

    public function testArchivesTheDataDirectoryWithTarFromTheDatabaseImageItself(): void
    {
        $snapshot = Path::fromString('/cache/snapshots/abcd--S--mariadb-11.4.tar.gz');

        $command = DockerCommandBuilder::archiveDataDirectory(instance: $this->instance(), snapshotPath: $snapshot)->build();

        self::assertSame([
            'docker',
            'run',
            '--rm',
            '--entrypoint',
            'tar',
            '--volume',
            'dan-test-data:/var/lib/mysql:ro',
            '--volume',
            '/cache/snapshots:/snapshot',
            'mariadb:11.4',
            '-czf',
            '/snapshot/abcd--S--mariadb-11.4.tar.gz',
            '-C',
            '/var/lib/mysql',
            '.',
        ], $command->arguments);
        self::assertNull($command->outputPath, 'The archive is written by tar inside the container, not through a shell redirection.');
    }

    public function testRestoresTheDataDirectoryIntoAWritableVolume(): void
    {
        $snapshot = Path::fromString('/cache/snapshots/abcd--S--mariadb-11.4.tar.gz');

        $command = DockerCommandBuilder::restoreDataDirectory(instance: $this->instance(), snapshotPath: $snapshot)->build();

        self::assertSame([
            'docker',
            'run',
            '--rm',
            '--entrypoint',
            'tar',
            '--volume',
            'dan-test-data:/var/lib/mysql',
            '--volume',
            '/cache/snapshots:/snapshot',
            'mariadb:11.4',
            '-xzf',
            '/snapshot/abcd--S--mariadb-11.4.tar.gz',
            '-C',
            '/var/lib/mysql',
        ], $command->arguments);
    }

    public function testRejectsASnapshotFileNameThatCouldBeReadAsAnOption(): void
    {
        $this->expectException(InvalidArgumentException::class);

        DockerCommandBuilder::archiveDataDirectory(instance: $this->instance(), snapshotPath: Path::fromString('/cache/--exclude=x.tar.gz'));
    }

    public function testTheReadinessProbeForcesTcpSoTheInitialisationServerCannotAnswerIt(): void
    {
        $command = DockerCommandBuilder::probeDatabase(
            instance: $this->instance(),
            rootPassword: 'dan',
        )->build();

        // The temporary server the entrypoint runs while initialising the
        // data directory listens on the socket only. A socket probe would
        // report ready before the real server has replaced it.
        self::assertContains('--protocol=TCP', $command->arguments);
        self::assertContains('--host=127.0.0.1', $command->arguments);
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
            instance: new DatabaseInstance(
                containerName: $containerName,
                target: new DatabaseTarget(engine: Engine::MySql, version: '8.4'),
                hostPort: 33060,
            ),
            rootPassword: 'dan',
        );
    }

    public function testRejectsAnImageTagThatCouldBeInterpretedAsArguments(): void
    {
        $this->expectException(InvalidArgumentException::class);

        DockerCommandBuilder::startDatabase(
            instance: new DatabaseInstance(
                containerName: 'dan-test',
                target: new DatabaseTarget(engine: Engine::MySql, version: '--privileged'),
                hostPort: 33060,
            ),
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
