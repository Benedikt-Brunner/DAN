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
 * grid cell, so implementations never share buffer pools or caches. Each
 * container keeps its data directory on a named volume, which is what makes
 * snapshots physical: stop cleanly, archive the volume, start again.
 */
final class DockerDatabaseManager implements DatabaseManager
{
    private const ROOT_PASSWORD = 'dan';
    private const START_TIMEOUT_SECONDS = 300;
    /** Long enough for mysqld to flush a large buffer pool on shutdown. */
    private const STOP_GRACE_SECONDS = 300;
    private const STOP_TIMEOUT_SECONDS = 360;
    private const ARCHIVE_TIMEOUT_SECONDS = 3600;
    private const READY_TIMEOUT_SECONDS = 120;
    private const READY_PROBE_TIMEOUT_SECONDS = 10;
    private const READY_RETRY_DELAY_SECONDS = 0.5;
    /** A stopped --rm container releases its volume asynchronously. */
    private const VOLUME_RELEASE_TIMEOUT_SECONDS = 30;

    public function __construct(
        private readonly ProcessRunner $processRunner = new SymfonyProcessRunner(),
    ) {}

    public function start(DatabaseTarget $target, string $containerName, ?Path $snapshot = null): DatabaseInstance
    {
        $instance = new DatabaseInstance(containerName: $containerName, target: $target, hostPort: TcpPortProvider::getPort());

        $this->processRunner->mustRun(DockerCommandBuilder::createVolume($instance)->build());
        if ($snapshot !== null) {
            $this->processRunner->mustRun(
                DockerCommandBuilder::restoreDataDirectory(instance: $instance, snapshotPath: $snapshot)
                    ->withTimeout(Duration::fromSeconds(self::ARCHIVE_TIMEOUT_SECONDS))
                    ->build(),
            );
        }
        $this->startServer($instance);

        return $instance;
    }

    public function stop(DatabaseInstance $instance): void
    {
        $this->stopServer($instance);
        $this->removeVolume($instance);
    }

    public function snapshot(DatabaseInstance $instance, Path $snapshot): void
    {
        // The archive must come from a cleanly stopped server: a datadir
        // copied under a running mysqld is not crash-consistent.
        $this->stopServer($instance);
        $this->processRunner->mustRun(
            DockerCommandBuilder::archiveDataDirectory(instance: $instance, snapshotPath: $snapshot)
                ->withTimeout(Duration::fromSeconds(self::ARCHIVE_TIMEOUT_SECONDS))
                ->build(),
        );
        $this->startServer($instance);
    }

    private function startServer(DatabaseInstance $instance): void
    {
        $this->processRunner->mustRun(
            DockerCommandBuilder::startDatabase(instance: $instance, rootPassword: self::ROOT_PASSWORD)
                ->withTimeout(Duration::fromSeconds(self::START_TIMEOUT_SECONDS))
                ->build(),
        );
        $this->waitUntilReady($instance);
    }

    private function stopServer(DatabaseInstance $instance): void
    {
        $this->processRunner->run(
            DockerCommandBuilder::stopDatabase(instance: $instance, gracePeriod: Duration::fromSeconds(self::STOP_GRACE_SECONDS))
                ->withTimeout(Duration::fromSeconds(self::STOP_TIMEOUT_SECONDS))
                ->build(),
        );
    }

    /**
     * docker stop returns when the process has exited, but the --rm removal
     * that releases the volume finishes moments later; until then the volume
     * counts as in use and cannot be removed. Retry briefly rather than leak
     * a data directory per cell.
     */
    private function removeVolume(DatabaseInstance $instance): void
    {
        $startedAt = Timestamp::now();
        $releaseTimeout = Duration::fromSeconds(self::VOLUME_RELEASE_TIMEOUT_SECONDS);
        do {
            if ($this->processRunner->run(DockerCommandBuilder::removeVolume($instance)->build())) {
                return;
            }
            Duration::fromSeconds(self::READY_RETRY_DELAY_SECONDS)->sleep();
        } while (!$startedAt->hasElapsed($releaseTimeout));

        throw new RuntimeException(sprintf('Data volume "%s" was still in use %d seconds after its container stopped.', $instance->dataVolume(), self::VOLUME_RELEASE_TIMEOUT_SECONDS));
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
