<?php

declare(strict_types=1);

namespace Dan\Harness\Tests\Database;

use Dan\Harness\Database\DatabaseInstance;
use Dan\Harness\Database\DatabaseManager;
use Dan\Harness\Database\DockerDatabaseManager;
use Dan\Harness\Process\ProcessCommand;
use Dan\Harness\Process\ProcessRunner;
use Dan\Harness\Protocol\DatabaseTarget;
use Dan\Harness\Protocol\Engine;
use Dan\Lib\Filesystem\Path;
use PHPUnit\Framework\TestCase;

final class DockerDatabaseManagerTest extends TestCase
{
    public function testImplementsDatabaseManagerContract(): void
    {
        self::assertInstanceOf(DatabaseManager::class, new DockerDatabaseManager());
    }

    public function testDelegatesDockerCommandsToTheProcessRunner(): void
    {
        $runner = new class implements ProcessRunner {
            /** @var list<ProcessCommand> */
            public array $commands = [];

            public function run(ProcessCommand $command): bool
            {
                $this->commands[] = $command;

                return true;
            }

            public function mustRun(ProcessCommand $command): void
            {
                $this->commands[] = $command;
            }
        };
        $manager = new DockerDatabaseManager($runner);
        $instance = new DatabaseInstance(
            containerName: 'dan-test',
            target: new DatabaseTarget(engine: Engine::MySql, version: '8.4'),
            hostPort: 33060,
        );
        $manager->importDump(instance: $instance, dumpPath: Path::fromString('/tmp/input.sql'));
        $manager->dumpTo(instance: $instance, dumpPath: Path::fromString('/tmp/output.sql'));
        $manager->stop($instance);

        self::assertSame([
            'exec',
            'exec',
            'stop',
        ], array_map(
            fn (ProcessCommand $command): string => $command->arguments[1],
            $runner->commands,
        ));
        self::assertSame('/tmp/input.sql', $runner->commands[0]->inputPath?->toString());
        self::assertSame('/tmp/output.sql', $runner->commands[1]->outputPath?->toString());
    }
}
