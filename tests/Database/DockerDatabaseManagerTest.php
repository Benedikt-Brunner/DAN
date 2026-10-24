<?php

declare(strict_types=1);

namespace Dan\Harness\Tests\Database;

use Dan\Harness\Database\DatabaseInstance;
use Dan\Harness\Database\DatabaseManager;
use Dan\Harness\Database\DockerDatabaseManager;
use Dan\Harness\Protocol\DatabaseTarget;
use Dan\Harness\Protocol\Engine;
use Dan\Lib\Filesystem\Path;
use PHPUnit\Framework\TestCase;

/**
 * The manager's Docker choreography against a recording runner: which
 * commands, in which order. Whether the choreography actually yields a
 * working, faithfully restored database is the integration test's job.
 */
final class DockerDatabaseManagerTest extends TestCase
{
    public function testImplementsDatabaseManagerContract(): void
    {
        self::assertInstanceOf(DatabaseManager::class, new DockerDatabaseManager());
    }

    public function testAFreshStartCreatesTheVolumeThenRunsTheServerAndWaitsForIt(): void
    {
        $runner = new RecordingProcessRunner();
        $manager = new DockerDatabaseManager($runner);

        $instance = $manager->start(target: new DatabaseTarget(engine: Engine::MySql, version: '8.4'), containerName: 'dan-test');

        self::assertSame('dan-test-data', $instance->dataVolume());
        self::assertSame([
            'docker volume create',
            'docker run',
            'docker exec',
        ], $runner->summaries());
        self::assertContains('dan-test-data:/var/lib/mysql', $runner->commands[1]->arguments);
    }

    public function testARestoredStartUnpacksTheSnapshotIntoTheVolumeBeforeTheServerRuns(): void
    {
        $runner = new RecordingProcessRunner();
        $manager = new DockerDatabaseManager($runner);

        $manager->start(
            target: new DatabaseTarget(engine: Engine::MySql, version: '8.4'),
            containerName: 'dan-test',
            snapshot: Path::fromString('/cache/abcd--S--mysql-8.4.tar.gz'),
        );

        self::assertSame([
            'docker volume create',
            'docker run',
            'docker run',
            'docker exec',
        ], $runner->summaries());
        self::assertSame('tar', $runner->commands[1]->arguments[4], 'The restore is a tar run on the volume.');
        self::assertContains('-xzf', $runner->commands[1]->arguments);
        self::assertSame('mysql:8.4', $runner->commands[2]->arguments[array_key_last($runner->commands[2]->arguments)], 'Then the server starts on the restored volume.');
    }

    public function testASnapshotStopsCleanlyArchivesTheVolumeAndBringsTheServerBack(): void
    {
        $runner = new RecordingProcessRunner();
        $manager = new DockerDatabaseManager($runner);

        $manager->snapshot(instance: $this->instance(), snapshot: Path::fromString('/cache/abcd--S--mysql-8.4.tar.gz'));

        self::assertSame([
            'docker stop',
            'docker run',
            'docker run',
            'docker exec',
        ], $runner->summaries());
        self::assertContains('--time', $runner->commands[0]->arguments, 'The server gets a grace period to flush before the archive is taken.');
        self::assertContains('-czf', $runner->commands[1]->arguments);
        self::assertContains('dan-test-data:/var/lib/mysql:ro', $runner->commands[1]->arguments);
        self::assertContains('dan-test-data:/var/lib/mysql', $runner->commands[2]->arguments);
    }

    public function testStoppingRemovesTheVolumeWithTheContainer(): void
    {
        $runner = new RecordingProcessRunner();
        $manager = new DockerDatabaseManager($runner);

        $manager->stop($this->instance());

        self::assertSame([
            'docker stop',
            'docker volume rm',
        ], $runner->summaries());
    }

    public function testStoppingRetriesTheVolumeRemovalUntilTheContainerHasReleasedIt(): void
    {
        $runner = new RecordingProcessRunner();
        // The first two removals hit "volume is in use" while the --rm
        // removal is still finishing; the third succeeds.
        $runner->failuresBeforeSuccess['docker volume rm'] = 2;
        $manager = new DockerDatabaseManager($runner);

        $manager->stop($this->instance());

        self::assertSame([
            'docker stop',
            'docker volume rm',
            'docker volume rm',
            'docker volume rm',
        ], $runner->summaries());
    }

    private function instance(): DatabaseInstance
    {
        return new DatabaseInstance(
            containerName: 'dan-test',
            target: new DatabaseTarget(engine: Engine::MySql, version: '8.4'),
            hostPort: 33060,
        );
    }
}
