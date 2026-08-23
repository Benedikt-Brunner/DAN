<?php

declare(strict_types=1);

namespace Dan\Harness\Implementation\Runtime;

use Dan\Harness\Database\DatabaseInstance;
use Dan\Harness\Process\OutputListener;
use Dan\Harness\Process\ProcessCommand;
use Dan\Harness\Process\ProcessRunner;
use Dan\Lib\Filesystem\Path;

/**
 * Executable runtime for a DAL implementation.
 *
 * Probe commands run from its Shopware skeleton working directory and connect
 * to the database instance selected for the current measurement cell.
 */
final class Runtime
{
    /**
     * The probe's console entry, staged next to the skeleton's bin/console by
     * the runtime factory.
     */
    private const DAN_CONSOLE_ENTRY = 'bin/dan-console';

    public function __construct(
        private readonly Path $workingDirectory,
        private readonly ProcessRunner $processRunner,
        private readonly ?OutputListener $outputListener = null,
    ) {}

    /**
     * Runs a probe command through DAN's console entry, which boots the
     * kernel with the recording middleware on the kernel connection.
     *
     * @param list<string> $args
     */
    public function run(array $args, DatabaseInstance $database): void
    {
        $this->console(entry: self::DAN_CONSOLE_ENTRY, args: $args, database: $database);
    }

    public function installShopware(DatabaseInstance $database): void
    {
        // Creates schema, runs migrations and basic setup against the (empty)
        // container. Only runs on snapshot cache misses - cache hits import a
        // full dump that already contains everything. Uses the skeleton's own
        // entry: its bootstrap handles the no-database INSTALL context.
        $this->console(entry: 'bin/console', args: [
            'system:install',
            '--create-database',
            '--basic-setup',
            '--force',
            '--no-interaction',
        ], database: $database);
    }

    /**
     * @param list<string> $args
     */
    private function console(string $entry, array $args, DatabaseInstance $database): void
    {
        $this->processRunner->mustRun(
            command: new ProcessCommand(
                arguments: [
                    'php',
                    '-d',
                    'memory_limit=-1',
                    $entry,
                    ...$args,
                ],
                timeout: null,
                workingDirectory: $this->workingDirectory,
                environment: [
                    'DATABASE_URL' => $database->databaseUrl(),
                    'APP_ENV' => 'prod',
                    'APP_SECRET' => 'dan-not-a-secret',
                    'APP_URL' => 'http://localhost:8000',
                    'SHOPWARE_SKIP_WEBINSTALLER' => '1',
                ],
            ),
            outputListener: $this->outputListener,
        );
    }
}
