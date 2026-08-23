<?php

declare(strict_types=1);

namespace Dan\Harness\Tests\Implementation\Runtime;

use Dan\Harness\Implementation\Identity\Identity;
use Dan\Harness\Implementation\Reference\Reference;
use Dan\Harness\Implementation\Runtime\RuntimeFactory;
use Dan\Harness\Process\OutputListener;
use Dan\Harness\Process\ProcessCommand;
use Dan\Harness\Process\ProcessRunner;
use Dan\Lib\Filesystem\Path;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\NullOutput;

final class RuntimeFactoryTest extends TestCase
{
    private string $workDirectory;

    private string $probeBundleDirectory;

    protected function setUp(): void
    {
        $this->workDirectory = sys_get_temp_dir() . '/dan-runtime-factory-' . bin2hex(random_bytes(4));
        mkdir($this->workDirectory);
        // The factory stages the probe's console entry from the bundle.
        $this->probeBundleDirectory = $this->workDirectory . '/bundle';
        mkdir($this->probeBundleDirectory . '/src/Resources/skeleton', 0o777, true);
        file_put_contents($this->probeBundleDirectory . '/src/Resources/skeleton/dan-console', "#!/usr/bin/env php\n<?php\n");
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->workDirectory);
    }

    public function testBuildsTheComposerCommandsForAReleaseReference(): void
    {
        $runner = $this->createSkeletonSimulatingRunner();
        $factory = new RuntimeFactory(
            runtimesDirectory: Path::fromString($this->workDirectory)->join('runtimes'),
            probeBundlePath: Path::fromString($this->probeBundleDirectory),
            processRunner: $runner,
        );

        $factory->create(
            reference: Reference::fromString('6.6.5.0'),
            identity: new Identity(id: 'release-6.6.5.0', label: '6.6.5.0'),
            output: new NullOutput(),
        );

        self::assertSame([
            [
                'composer',
                'create-project',
                '--no-install',
                '--no-interaction',
                'shopware/production',
                '.',
            ],
            [
                'composer',
                'config',
                'repositories.dan-probe',
                json_encode([
                    'type' => 'path',
                    'url' => $this->probeBundleDirectory,
                    'options' => ['symlink' => true],
                ], \JSON_THROW_ON_ERROR),
            ],
            [
                'composer',
                'config',
                'repositories.dan-lib',
                json_encode([
                    'type' => 'path',
                    'url' => $this->workDirectory . '/lib',
                    'options' => ['symlink' => true],
                ], \JSON_THROW_ON_ERROR),
            ],
            [
                'composer',
                'config',
                'minimum-stability',
                'dev',
            ],
            [
                'composer',
                'config',
                'prefer-stable',
                'true',
            ],
            [
                'composer',
                'require',
                '--no-interaction',
                '--no-scripts',
                'shopware/core:6.6.5.0',
                'dan/probe:*',
            ],
        ], array_map(
            fn (ProcessCommand $command): array => $command->arguments,
            $runner->commands,
        ));
    }

    public function testBuildsTheComposerCommandsForACheckoutReference(): void
    {
        $checkoutDirectory = $this->createCheckoutDirectory();
        $runner = $this->createSkeletonSimulatingRunner();
        $factory = new RuntimeFactory(
            runtimesDirectory: Path::fromString($this->workDirectory)->join('runtimes'),
            probeBundlePath: Path::fromString($this->probeBundleDirectory),
            processRunner: $runner,
        );

        $factory->create(
            reference: Reference::fromString($checkoutDirectory),
            identity: new Identity(id: 'checkout-abc123', label: 'my-checkout'),
            output: new NullOutput(),
        );

        $argumentVectors = array_map(
            fn (ProcessCommand $command): array => $command->arguments,
            $runner->commands,
        );
        $coreJson = json_encode([
            'type' => 'path',
            'url' => $checkoutDirectory . '/src/Core',
            'options' => ['symlink' => true],
        ], \JSON_THROW_ON_ERROR);
        self::assertContains([
            'composer',
            'config',
            'repositories.dal-under-test',
            $coreJson,
        ], $argumentVectors);
        self::assertContains([
            'composer',
            'config',
            'minimum-stability',
            'dev',
        ], $argumentVectors);
        self::assertContains([
            'composer',
            'config',
            'prefer-stable',
            'true',
        ], $argumentVectors);
        self::assertSame([
            'composer',
            'require',
            '--no-interaction',
            '--no-scripts',
            'shopware/core:*',
            'dan/probe:*',
        ], $argumentVectors[count($argumentVectors) - 1]);
    }

    public function testRunsEveryComposerCommandInsideTheRuntimeDirectory(): void
    {
        $runner = $this->createSkeletonSimulatingRunner();
        $factory = new RuntimeFactory(
            runtimesDirectory: Path::fromString($this->workDirectory)->join('runtimes'),
            probeBundlePath: Path::fromString($this->probeBundleDirectory),
            processRunner: $runner,
        );

        $factory->create(
            reference: Reference::fromString('6.6.5.0'),
            identity: new Identity(id: 'release 6.6.5.0', label: '6.6.5.0'),
            output: new NullOutput(),
        );

        $expectedRuntimeDirectory = $this->workDirectory . '/runtimes/release_6.6.5.0';
        self::assertNotSame([], $runner->commands);
        foreach ($runner->commands as $command) {
            self::assertSame($expectedRuntimeDirectory, $command->workingDirectory?->toString());
        }
        self::assertStringContainsString(
            'DanProbeBundle',
            (string) file_get_contents($expectedRuntimeDirectory . '/config/bundles.php'),
        );
    }

    public function testReusesAnExistingRuntimeWithoutRunningComposer(): void
    {
        $runner = $this->createSkeletonSimulatingRunner();
        $runtimeDirectory = $this->workDirectory . '/runtimes/release-6.6.5.0';
        mkdir($runtimeDirectory . '/bin', 0o777, true);
        touch($runtimeDirectory . '/.dan-runtime');
        $factory = new RuntimeFactory(
            runtimesDirectory: Path::fromString($this->workDirectory)->join('runtimes'),
            probeBundlePath: Path::fromString($this->probeBundleDirectory),
            processRunner: $runner,
        );

        $factory->create(
            reference: Reference::fromString('6.6.5.0'),
            identity: new Identity(id: 'release-6.6.5.0', label: '6.6.5.0'),
            output: new NullOutput(),
        );

        self::assertSame([], $runner->commands);
        // Reuse still re-stages the console entry so probe updates propagate.
        self::assertFileExists($runtimeDirectory . '/bin/dan-console');
    }

    /**
     * @return ProcessRunner&object{commands: list<ProcessCommand>}
     */
    private function createSkeletonSimulatingRunner(): ProcessRunner
    {
        return new class implements ProcessRunner {
            /** @var list<ProcessCommand> */
            public array $commands = [];

            public function run(ProcessCommand $command): bool
            {
                $this->commands[] = $command;

                return true;
            }

            public function mustRun(ProcessCommand $command, ?OutputListener $outputListener = null): void
            {
                $this->commands[] = $command;
                // "composer require" triggers the Flex recipes that generate
                // config/bundles.php in a real skeleton - simulate that so the
                // factory can register the probe bundle afterwards.
                if (($command->arguments[1] ?? null) !== 'require' || $command->workingDirectory === null) {
                    return;
                }
                $configDirectory = $command->workingDirectory->join('config')->toString();
                if (!is_dir($configDirectory)) {
                    mkdir($configDirectory, 0o777, true);
                }
                file_put_contents(
                    $command->workingDirectory->join('config', 'bundles.php')->toString(),
                    "<?php\n\nreturn [\n    Shopware\\Core\\Framework\\Framework::class => ['all' => true],\n];\n",
                );
                // A real skeleton ships bin/ - the factory stages
                // bin/dan-console next to bin/console afterwards.
                $binDirectory = $command->workingDirectory->join('bin')->toString();
                if (!is_dir($binDirectory)) {
                    mkdir($binDirectory, 0o777, true);
                }
            }
        };
    }

    private function createCheckoutDirectory(): string
    {
        $checkoutDirectory = $this->workDirectory . '/checkout';
        mkdir($checkoutDirectory . '/src/Core', 0o777, true);
        file_put_contents($checkoutDirectory . '/src/Core/composer.json', '{"name": "shopware/core"}');
        $resolvedCheckoutDirectory = realpath($checkoutDirectory);
        self::assertNotFalse($resolvedCheckoutDirectory);

        return $resolvedCheckoutDirectory;
    }

    private function removeDirectory(string $directory): void
    {
        foreach (glob($directory . '/{,.}[!.,!..]*', \GLOB_BRACE) ?: [] as $path) {
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($directory);
    }
}
