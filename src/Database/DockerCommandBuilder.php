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

    public static function startDatabase(DatabaseTarget $target, string $containerName, int $hostPort, string $rootPassword): self
    {
        self::validateContainerName($containerName);
        if ($hostPort < 1 || $hostPort > 65535) {
            throw new InvalidArgumentException(sprintf('Invalid TCP port %d.', $hostPort));
        }

        return new self([
            'docker',
            'run',
            '--detach',
            '--rm',
            '--name',
            $containerName,
            '--env',
            'MYSQL_ROOT_PASSWORD=' . $rootPassword,
            '--env',
            'MYSQL_DATABASE=dan',
            '--env',
            'MARIADB_ROOT_PASSWORD=' . $rootPassword,
            '--env',
            'MARIADB_DATABASE=dan',
            '--publish',
            sprintf('127.0.0.1:%d:3306', $hostPort),
            self::getImageIdentifier($target),
        ]);
    }

    public static function stopDatabase(DatabaseInstance $instance): self
    {
        self::validateContainerName($instance->containerName);

        return new self([
            'docker',
            'stop',
            '--',
            $instance->containerName,
        ]);
    }

    public static function importDatabase(DatabaseInstance $instance, Path $dumpPath, string $rootPassword): self
    {
        return self::databaseClient(
            instance: $instance,
            rootPassword: $rootPassword,
            clientArguments: [],
            inputPath: $dumpPath,
        );
    }

    public static function dumpDatabase(DatabaseInstance $instance, Path $dumpPath, string $rootPassword): self
    {
        self::validateContainerName($instance->containerName);

        return new self(
            arguments: [
                'docker',
                'exec',
                '--',
                $instance->containerName,
                $instance->dumpBinary(),
                '-uroot',
                '-p' . $rootPassword,
                '--single-transaction',
                '--routines',
                '--triggers',
                'dan',
            ],
            outputPath: $dumpPath,
        );
    }

    public static function probeDatabase(DatabaseInstance $instance, string $rootPassword): self
    {
        return self::databaseClient(
            instance: $instance,
            rootPassword: $rootPassword,
            clientArguments: [
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
