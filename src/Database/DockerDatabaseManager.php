<?php

declare(strict_types=1);

namespace Dan\Harness\Database;

use Dan\Harness\Process\ProcessRunner;
use Dan\Harness\Process\SymfonyProcessRunner;
use Dan\Harness\Protocol\DatabaseTarget;
use Dan\Lib\Filesystem\Path;
use Dan\Lib\Time\Duration;
use Dan\Lib\Time\Timestamp;
use RuntimeException;

/**
 * Starts and stops throwaway database containers - one per implementation per
 * grid cell, so implementations never share buffer pools or caches.
 */
final class DockerDatabaseManager implements DatabaseManager
{
    private const ROOT_PASSWORD = 'dan';
    private const START_TIMEOUT_SECONDS = 300;
    private const STOP_TIMEOUT_SECONDS = 60;
    private const READY_TIMEOUT_SECONDS = 120;
    private const READY_PROBE_TIMEOUT_SECONDS = 10;
    private const READY_RETRY_DELAY_SECONDS = 0.5;

    public function __construct(
        private readonly ProcessRunner $processRunner = new SymfonyProcessRunner(),
    ) {}

    public function start(DatabaseTarget $target, string $containerName): DatabaseInstance
    {
        $port = TcpPortProvider::getPort();

        $this->processRunner->mustRun(
            DockerCommandBuilder::startDatabase(
                target: $target,
                containerName: $containerName,
                hostPort: $port,
                rootPassword: self::ROOT_PASSWORD,
            )
                ->withTimeout(Duration::fromSeconds(self::START_TIMEOUT_SECONDS))
                ->build(),
        );

        $instance = new DatabaseInstance(containerName: $containerName, target: $target, hostPort: $port);
        $this->waitUntilReady($instance);

        return $instance;
    }

    public function stop(DatabaseInstance $instance): void
    {
        $this->processRunner->run(
            DockerCommandBuilder::stopDatabase($instance)
                ->withTimeout(Duration::fromSeconds(self::STOP_TIMEOUT_SECONDS))
                ->build(),
        );
    }

    public function importDump(DatabaseInstance $instance, Path $dumpPath): void
    {
        $this->processRunner->mustRun(
            DockerCommandBuilder::importDatabase(
                instance: $instance,
                dumpPath: $dumpPath,
                rootPassword: self::ROOT_PASSWORD,
            )->build(),
        );
    }

    public function dumpTo(DatabaseInstance $instance, Path $dumpPath): void
    {
        $this->processRunner->mustRun(
            DockerCommandBuilder::dumpDatabase(
                instance: $instance,
                dumpPath: $dumpPath,
                rootPassword: self::ROOT_PASSWORD,
            )->build(),
        );
    }

    private function waitUntilReady(DatabaseInstance $instance): void
    {
        $startedAt = Timestamp::now();
        $readyTimeout = Duration::fromSeconds(self::READY_TIMEOUT_SECONDS);
        do {
            $isReady = $this->processRunner->run(
                DockerCommandBuilder::probeDatabase(
                    instance: $instance,
                    rootPassword: self::ROOT_PASSWORD,
                )
                    ->withTimeout(Duration::fromSeconds(self::READY_PROBE_TIMEOUT_SECONDS))
                    ->build(),
            );
            if ($isReady) {
                return;
            }
            Duration::fromSeconds(self::READY_RETRY_DELAY_SECONDS)->sleep();
        } while (!$startedAt->hasElapsed($readyTimeout));

        $this->stop($instance);

        throw new RuntimeException(sprintf('Database container "%s" (%s:%s) did not become ready within %d seconds.', $instance->containerName, $instance->target->engine->value, $instance->target->version, self::READY_TIMEOUT_SECONDS));
    }
}
