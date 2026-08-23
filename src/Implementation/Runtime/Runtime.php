<?php

declare(strict_types=1);

namespace Dan\Harness\Implementation\Runtime;

use Dan\Harness\Database\DatabaseInstance;
use Dan\Lib\Filesystem\Path;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

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
        private readonly OutputInterface $output,
    ) {}

    /**
     * Runs a probe command through DAN's console entry, which boots the
     * kernel with the recording middleware on the kernel connection.
     *
     * @param list<string> $args
     */
    public function run(array $args, DatabaseInstance $database): void
    {
        $this->console(entry: 'bin/dan-console', args: $args, database: $database);
    }

    /**
     * @param list<string> $args
     */
    private function console(string $entry, array $args, DatabaseInstance $database): void
    {
        $process = new Process(
            [
                'php',
                '-d',
                'memory_limit=-1',
                $entry,
                ...$args,
            ],
            $this->workingDirectory->toString(),
            [
                'DATABASE_URL' => $database->databaseUrl(),
                'APP_ENV' => 'prod',
                'APP_SECRET' => 'dan-not-a-secret',
                'APP_URL' => 'http://localhost:8000',
                'SHOPWARE_SKIP_WEBINSTALLER' => '1',
            ],
            timeout: null,
        );
        $process->mustRun(function (string $type, string $buffer): void {
            if ($this->output->isVerbose()) {
                $this->output->write($buffer);
            }
        });
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
}
