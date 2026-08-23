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
    public function __construct(
        private readonly Path $workingDirectory,
        private readonly ProcessRunner $processRunner,
        private readonly ?OutputListener $outputListener = null,
    ) {}

    /**
     * @param list<string> $args
     */
    public function run(array $args, DatabaseInstance $database): void
    {
        $this->processRunner->mustRun(
            command: new ProcessCommand(
                arguments: [
                    'php',
                    '-d',
                    'memory_limit=-1',
                    'bin/console',
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

    public function installShopware(DatabaseInstance $database): void
    {
        // Creates schema, runs migrations and basic setup against the (empty)
        // container. Only runs on snapshot cache misses - cache hits import a
        // full dump that already contains everything.
        $this->run(args: [
            'system:install',
            '--create-database',
            '--basic-setup',
            '--force',
            '--no-interaction',
        ], database: $database);
    }
}
