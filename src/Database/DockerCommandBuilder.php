<?php

declare(strict_types=1);

namespace Dan\Harness\Database;

use Dan\Harness\Process\ProcessCommand;
use Dan\Harness\Protocol\DatabaseTarget;
use Dan\Lib\Filesystem\Path;
use Dan\Lib\Time\Duration;
use InvalidArgumentException;

final class DockerCommandBuilder
{
    /**
     * @param non-empty-list<string> $arguments
     */
    private function __construct(
        private readonly array $arguments,
        private readonly ?Path $inputPath = null,
        private readonly ?Path $outputPath = null,
        private ?Duration $timeout = null,
    ) {}

    private const string DATA_DIRECTORY = '/var/lib/mysql';
    private const string SNAPSHOT_MOUNT = '/snapshot';

    /**
     * Starts the server with its data directory on the instance's named
     * volume. An empty volume makes the image's entrypoint initialise a fresh
     * database with the given credentials; a volume restored from a snapshot
     * already holds one, and the entrypoint skips initialisation.
     */
    public static function startDatabase(DatabaseInstance $instance, string $rootPassword): self
    {
        self::validateContainerName($instance->containerName);
        if ($instance->hostPort < 1 || $instance->hostPort > 65535) {
            throw new InvalidArgumentException(sprintf('Invalid TCP port %d.', $instance->hostPort));
        }

        return new self([
            'docker',
            'run',
            '--detach',
            '--rm',
            '--name',
            $instance->containerName,
            '--env',
            'MYSQL_ROOT_PASSWORD=' . $rootPassword,
            '--env',
            'MYSQL_DATABASE=dan',
            '--env',
            'MARIADB_ROOT_PASSWORD=' . $rootPassword,
            '--env',
            'MARIADB_DATABASE=dan',
            '--volume',
            $instance->dataVolume() . ':' . self::DATA_DIRECTORY,
            '--publish',
            sprintf('127.0.0.1:%d:3306', $instance->hostPort),
            self::getImageIdentifier($instance->target),
        ]);
    }

    /**
     * The engine's self-description as JSON on stdout: version, host OS and
     * resources, and the published-port forwarding mode.
     */
    public static function engineInfo(Path $outputPath): self
    {
        return new self(
            arguments: [
                'docker',
                'info',
                '--format',
                '{{json .}}',
            ],
            outputPath: $outputPath,
        );
    }

    /**
     * The first repository digest of a locally present image, e.g.
     * "mysql@sha256:...", on stdout. Fails when the image is not pulled yet.
     */
    public static function imageDigest(DatabaseTarget $target, Path $outputPath): self
    {
        return new self(
            arguments: [
                'docker',
                'image',
                'inspect',
                '--format',
                '{{index .RepoDigests 0}}',
                '--',
                self::getImageIdentifier($target),
            ],
            outputPath: $outputPath,
        );
    }

    public static function pullImage(DatabaseTarget $target): self
    {
        return new self([
            'docker',
            'pull',
            '--quiet',
            '--',
            self::getImageIdentifier($target),
        ]);
    }

    /**
     * SIGTERM, then the grace period for mysqld to flush and close cleanly -
     * a snapshot taken from the volume afterwards is crash-consistent only if
     * the shutdown completed. The container removes itself; the volume stays.
     */
    public static function stopDatabase(DatabaseInstance $instance, Duration $gracePeriod): self
    {
        self::validateContainerName($instance->containerName);

        return new self([
            'docker',
            'stop',
            '--time',
            (string) (int) ceil($gracePeriod->toSecondsFloat()),
            '--',
            $instance->containerName,
        ]);
    }

    public static function createVolume(DatabaseInstance $instance): self
    {
        self::validateContainerName($instance->dataVolume());

        return new self([
            'docker',
            'volume',
            'create',
            '--',
            $instance->dataVolume(),
        ]);
    }

    public static function removeVolume(DatabaseInstance $instance): self
    {
        self::validateContainerName($instance->dataVolume());

        return new self([
            'docker',
            'volume',
            'rm',
            '--force',
            '--',
            $instance->dataVolume(),
        ]);
    }

    /**
     * Archives the stopped server's data directory into the snapshot file.
     * Runs tar from the database image itself so no helper image has to be
     * pulled; the snapshot's directory is bind-mounted, the file named inside.
     */
    public static function archiveDataDirectory(DatabaseInstance $instance, Path $snapshotPath): self
    {
        return self::dataDirectoryTar(instance: $instance, snapshotPath: $snapshotPath, tarArguments: [
            '-czf',
            self::SNAPSHOT_MOUNT . '/' . $snapshotPath->basename(),
            '-C',
            self::DATA_DIRECTORY,
            '.',
        ], readOnlyVolume: true);
    }

    /**
     * Unpacks a snapshot into the instance's (empty) data volume before the
     * server is started on it.
     */
    public static function restoreDataDirectory(DatabaseInstance $instance, Path $snapshotPath): self
    {
        return self::dataDirectoryTar(instance: $instance, snapshotPath: $snapshotPath, tarArguments: [
            '-xzf',
            self::SNAPSHOT_MOUNT . '/' . $snapshotPath->basename(),
            '-C',
            self::DATA_DIRECTORY,
        ], readOnlyVolume: false);
    }

    public static function probeDatabase(DatabaseInstance $instance, string $rootPassword): self
    {
        return self::databaseClient(
            instance: $instance,
            rootPassword: $rootPassword,
            clientArguments: [
                // Both official entrypoints run a temporary server with
                // networking disabled to initialise the data directory, then
                // shut it down and start the real one. A socket probe answers
                // during that phase, so readiness would be declared moments
                // before the socket disappears again; TCP only answers once
                // the real server is listening.
                '--protocol=TCP',
                '--host=127.0.0.1',
                '--execute',
                'SELECT 1',
            ],
        );
    }

    public function withTimeout(?Duration $timeout): self
    {
        $this->timeout = $timeout;

        return $this;
    }

    public function build(): ProcessCommand
    {
        return new ProcessCommand(
            arguments: $this->arguments,
            timeout: $this->timeout,
            inputPath: $this->inputPath,
            outputPath: $this->outputPath,
        );
    }

    /**
     * @param list<string> $tarArguments
     */
    private static function dataDirectoryTar(DatabaseInstance $instance, Path $snapshotPath, array $tarArguments, bool $readOnlyVolume): self
    {
        self::validateContainerName($instance->dataVolume());
        if (str_contains($snapshotPath->basename(), ':') || str_starts_with($snapshotPath->basename(), '-')) {
            throw new InvalidArgumentException(sprintf('Invalid snapshot file name "%s".', $snapshotPath->basename()));
        }

        return new self([
            'docker',
            'run',
            '--rm',
            '--entrypoint',
            'tar',
            '--volume',
            $instance->dataVolume() . ':' . self::DATA_DIRECTORY . ($readOnlyVolume ? ':ro' : ''),
            '--volume',
            $snapshotPath->parent()->toString() . ':' . self::SNAPSHOT_MOUNT,
            self::getImageIdentifier($instance->target),
            ...$tarArguments,
        ]);
    }

    /**
     * @param list<string> $clientArguments
     */
    private static function databaseClient(DatabaseInstance $instance, string $rootPassword, array $clientArguments, ?Path $inputPath = null): self
    {
        self::validateContainerName($instance->containerName);

        return new self(
            arguments: [
                'docker',
                'exec',
                '--interactive',
                '--',
                $instance->containerName,
                $instance->clientBinary(),
                '-uroot',
                '-p' . $rootPassword,
                ...$clientArguments,
                'dan',
            ],
            inputPath: $inputPath,
        );
    }

    private static function getImageIdentifier(DatabaseTarget $target): string
    {
        if (preg_match('/\A[A-Za-z0-9_][A-Za-z0-9_.-]{0,127}\z/', $target->version) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid Docker image tag "%s".', $target->version));
        }

        return $target->engine->value . ':' . $target->version;
    }

    private static function validateContainerName(string $containerName): void
    {
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]+\z/', $containerName) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid Docker container name "%s".', $containerName));
        }
    }
}
