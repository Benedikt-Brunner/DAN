<?php

declare(strict_types=1);

namespace Dan\Harness\Environment;

use Dan\Harness\Database\DockerCommandBuilder;
use Dan\Harness\Implementation\Identity\ContentFingerprint;
use Dan\Harness\Process\ProcessCommand;
use Dan\Harness\Process\ProcessRunner;
use Dan\Harness\Protocol\DatabaseTarget;
use Dan\Harness\Protocol\Protocol;
use Dan\Lib\Filesystem\Path;
use Dan\Lib\Time\Duration;
use RuntimeException;

/**
 * Discovers the execution environment of a session. Every external probe
 * (composer, docker, sysctl) is allowed to be absent or to fail: the field
 * is then recorded as unknown, because a manifest that cannot be written
 * without Docker's cooperation would block measurements over bookkeeping.
 * The only hard requirement is DAN's own source tree, which fingerprints
 * itself.
 */
final class EnvironmentResolver
{
    private const int PROBE_TIMEOUT_SECONDS = 60;
    private const int PULL_TIMEOUT_SECONDS = 600;

    /**
     * @param Path $danRoot the DAN repository root (its src/, lib/src and bundle/src are fingerprinted)
     * @param Path|null $systemRoot where /proc and /sys live; only tests point this elsewhere
     */
    public function __construct(
        private readonly Path $danRoot,
        private readonly ProcessRunner $processRunner,
        private readonly ?Path $systemRoot = null,
    ) {}

    public function resolve(Protocol $protocol): ExecutionEnvironment
    {
        return new ExecutionEnvironment(
            danRevision: ContentFingerprint::ofDirectories([
                'bundle' => $this->danRoot->join('bundle', 'src'),
                'lib' => $this->danRoot->join('lib', 'src'),
                'src' => $this->danRoot->join('src'),
            ]),
            phpVersion: \PHP_VERSION,
            composerVersion: $this->composerVersion(),
            host: $this->host(),
            dockerEngine: $this->dockerEngine(),
            databaseImages: array_map($this->databaseImage(...), $protocol->databases),
            databaseNetworkPath: DatabaseNetworkPath::PublishedPort,
        );
    }

    private function composerVersion(): ?string
    {
        $output = $this->capture(fn (Path $outputPath): ProcessCommand => new ProcessCommand(
            arguments: [
                'composer',
                '--version',
                '--no-ansi',
                '--no-interaction',
            ],
            timeout: Duration::fromSeconds(self::PROBE_TIMEOUT_SECONDS),
            outputPath: $outputPath,
        ));
        if ($output === null || preg_match('/Composer version (\S+)/', $output, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    private function host(): HostMachine
    {
        return new HostMachine(
            operatingSystem: php_uname('s') . ' ' . php_uname('r'),
            architecture: php_uname('m'),
            cpuModel: $this->cpuModel(),
            cpuLimit: $this->cpuLimit(),
            memoryLimitBytes: $this->memoryLimit(),
        );
    }

    private function cpuModel(): ?string
    {
        $cpuinfo = $this->readSystemFile('proc', 'cpuinfo');
        if ($cpuinfo !== null) {
            return preg_match('/^model name\s*:\s*(.+)$/m', $cpuinfo, $matches) === 1 ? trim($matches[1]) : null;
        }

        // Darwin has no /proc; sysctl is the one place the model is spelled out.
        $brand = $this->capture(fn (Path $outputPath): ProcessCommand => new ProcessCommand(
            arguments: [
                'sysctl',
                '-n',
                'machdep.cpu.brand_string',
            ],
            timeout: Duration::fromSeconds(self::PROBE_TIMEOUT_SECONDS),
            outputPath: $outputPath,
        ));
        $brand = $brand === null ? '' : trim($brand);

        return $brand === '' ? null : $brand;
    }

    /**
     * cgroup v2 cpu.max: "<quota> <period>" in microseconds, or "max".
     */
    private function cpuLimit(): ?float
    {
        $cpuMax = $this->readSystemFile('sys', 'fs', 'cgroup', 'cpu.max');
        if ($cpuMax === null || preg_match('/^(\d+)\s+(\d+)\s*$/', trim($cpuMax), $matches) !== 1) {
            return null;
        }

        return (int) $matches[1] / (int) $matches[2];
    }

    /**
     * cgroup v2 memory.max: bytes, or "max".
     */
    private function memoryLimit(): ?int
    {
        $memoryMax = $this->readSystemFile('sys', 'fs', 'cgroup', 'memory.max');
        if ($memoryMax === null || !ctype_digit(trim($memoryMax))) {
            return null;
        }

        return (int) trim($memoryMax);
    }

    private function dockerEngine(): ?DockerEngine
    {
        $output = $this->capture(fn (Path $outputPath): ProcessCommand => DockerCommandBuilder::engineInfo($outputPath)
            ->withTimeout(Duration::fromSeconds(self::PROBE_TIMEOUT_SECONDS))
            ->build());
        if ($output === null) {
            return null;
        }
        $info = json_decode($output, true);
        if (!is_array($info)) {
            return null;
        }
        $version = $info['ServerVersion'] ?? null;
        $operatingSystem = $info['OperatingSystem'] ?? null;
        $architecture = $info['Architecture'] ?? null;
        $cpus = $info['NCPU'] ?? null;
        $memoryBytes = $info['MemTotal'] ?? null;
        if (!is_string($version) || !is_string($operatingSystem) || !is_string($architecture) || !is_int($cpus) || !is_int($memoryBytes)) {
            return null;
        }

        return new DockerEngine(
            version: $version,
            operatingSystem: $operatingSystem,
            architecture: $architecture,
            cpus: $cpus,
            memoryBytes: $memoryBytes,
            userlandProxy: self::userlandProxy($info),
        );
    }

    /**
     * Docker 28+ reports its firewall backend with an "EnableUserlandProxy"
     * key/value pair; older engines say nothing, and nothing is what is
     * recorded then.
     *
     * @param array<mixed> $info
     */
    private static function userlandProxy(array $info): ?bool
    {
        $firewall = $info['FirewallBackend'] ?? null;
        $pairs = is_array($firewall) ? ($firewall['Info'] ?? null) : null;
        if (!is_array($pairs)) {
            return null;
        }
        foreach ($pairs as $pair) {
            if (is_array($pair) && ($pair[0] ?? null) === 'EnableUserlandProxy' && is_string($pair[1] ?? null)) {
                return $pair[1] === 'true';
            }
        }

        return null;
    }

    private function databaseImage(DatabaseTarget $target): DatabaseImage
    {
        $digest = $this->imageDigest($target);
        if ($digest === null) {
            // Not pulled yet - the measurement would pull it anyway; doing it
            // now is what makes the digest recordable up front.
            $this->processRunner->run(
                DockerCommandBuilder::pullImage($target)
                    ->withTimeout(Duration::fromSeconds(self::PULL_TIMEOUT_SECONDS))
                    ->build(),
            );
            $digest = $this->imageDigest($target);
        }

        return new DatabaseImage(target: $target, digest: $digest);
    }

    private function imageDigest(DatabaseTarget $target): ?string
    {
        $output = $this->capture(fn (Path $outputPath): ProcessCommand => DockerCommandBuilder::imageDigest(target: $target, outputPath: $outputPath)
            ->withTimeout(Duration::fromSeconds(self::PROBE_TIMEOUT_SECONDS))
            ->build());
        if ($output === null || preg_match('/@(sha256:[0-9a-f]{64})/', $output, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    /**
     * Runs a probe command with stdout captured into a scratch file; null
     * when the command is unavailable or fails.
     *
     * @param callable(Path): ProcessCommand $command
     */
    private function capture(callable $command): ?string
    {
        $scratch = tempnam(sys_get_temp_dir(), 'dan-environment-');
        if ($scratch === false) {
            throw new RuntimeException('Could not create a scratch file for environment discovery.');
        }
        $outputPath = Path::fromString($scratch);

        try {
            if (!$this->processRunner->run($command($outputPath))) {
                return null;
            }
            $output = file_get_contents($outputPath->toString());

            return $output === false ? null : $output;
        } finally {
            @unlink($outputPath->toString());
        }
    }

    private function readSystemFile(string ...$segments): ?string
    {
        $path = ($this->systemRoot ?? Path::fromString('/'))->join(...$segments)->toString();
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }
        $content = file_get_contents($path);

        return $content === false ? null : $content;
    }
}
