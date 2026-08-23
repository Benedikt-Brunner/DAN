<?php

declare(strict_types=1);

namespace Dan\Harness\Tests\Implementation\Runtime;

use Dan\Harness\Database\DatabaseInstance;
use Dan\Harness\Implementation\Runtime\Runtime;
use Dan\Harness\Process\OutputListener;
use Dan\Harness\Process\ProcessCommand;
use Dan\Harness\Process\ProcessRunner;
use Dan\Harness\Protocol\DatabaseTarget;
use Dan\Harness\Protocol\Engine;
use Dan\Lib\Filesystem\Path;
use PHPUnit\Framework\TestCase;

final class RuntimeTest extends TestCase
{
    public function testRunsBinConsoleFromTheWorkingDirectoryWithTheCellDatabaseEnvironment(): void
    {
        $runner = $this->createRecordingRunner();
        $runtime = new Runtime(
            workingDirectory: Path::fromString('/runtimes/release-6.6.5.0'),
            processRunner: $runner,
        );

        $runtime->run(args: [
            'dan:execute',
            '--scenario=product-search',
        ], database: $this->createDatabaseInstance());

        self::assertCount(1, $runner->commands);
        $command = $runner->commands[0];
        self::assertSame([
            'php',
            '-d',
            'memory_limit=-1',
            'bin/console',
            'dan:execute',
            '--scenario=product-search',
        ], $command->arguments);
        self::assertSame('/runtimes/release-6.6.5.0', $command->workingDirectory?->toString());
        self::assertSame([
            'DATABASE_URL' => 'mysql://root:dan@127.0.0.1:33060/dan',
            'APP_ENV' => 'prod',
            'APP_SECRET' => 'dan-not-a-secret',
            'APP_URL' => 'http://localhost:8000',
            'SHOPWARE_SKIP_WEBINSTALLER' => '1',
        ], $command->environment);
        self::assertNull($command->timeout);
    }

    public function testInstallShopwareRunsSystemInstall(): void
    {
        $runner = $this->createRecordingRunner();
        $runtime = new Runtime(
            workingDirectory: Path::fromString('/runtimes/release-6.6.5.0'),
            processRunner: $runner,
        );

        $runtime->installShopware($this->createDatabaseInstance());

        self::assertCount(1, $runner->commands);
        self::assertSame([
            'php',
            '-d',
            'memory_limit=-1',
            'bin/console',
            'system:install',
            '--create-database',
            '--basic-setup',
            '--force',
            '--no-interaction',
        ], $runner->commands[0]->arguments);
    }

    public function testPassesItsOutputListenerToTheRunner(): void
    {
        $runner = $this->createRecordingRunner();
        $listener = new class implements OutputListener {
            public function onOutput(string $data): void {}
        };
        $runtime = new Runtime(
            workingDirectory: Path::fromString('/runtimes/release-6.6.5.0'),
            processRunner: $runner,
            outputListener: $listener,
        );

        $runtime->run(args: ['dan:execute'], database: $this->createDatabaseInstance());

        self::assertSame([$listener], $runner->outputListeners);
    }

    /**
     * @return ProcessRunner&object{commands: list<ProcessCommand>, outputListeners: list<?OutputListener>}
     */
    private function createRecordingRunner(): ProcessRunner
    {
        return new class implements ProcessRunner {
            /** @var list<ProcessCommand> */
            public array $commands = [];

            /** @var list<?OutputListener> */
            public array $outputListeners = [];

            public function run(ProcessCommand $command): bool
            {
                $this->commands[] = $command;
                $this->outputListeners[] = null;

                return true;
            }

            public function mustRun(ProcessCommand $command, ?OutputListener $outputListener = null): void
            {
                $this->commands[] = $command;
                $this->outputListeners[] = $outputListener;
            }
        };
    }

    private function createDatabaseInstance(): DatabaseInstance
    {
        return new DatabaseInstance(
            containerName: 'dan-test',
            target: new DatabaseTarget(engine: Engine::MySql, version: '8.4'),
            hostPort: 33060,
        );
    }
}
