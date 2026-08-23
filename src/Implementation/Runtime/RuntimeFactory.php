<?php

declare(strict_types=1);

namespace Dan\Harness\Implementation\Runtime;

use Dan\Harness\Implementation\Identity\Identity;
use Dan\Harness\Implementation\Reference\Reference;
use Dan\Harness\Implementation\Reference\ReferenceType;
use Dan\Lib\Filesystem\Path;
use RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

/**
 * Builds an executable runtime for a DAL implementation and wires the DAN
 * probe into its Shopware skeleton.
 *
 * - Released versions: shopware/core is required from packagist at the pinned
 *   version.
 * - Checkouts: shopware/core is wired via a Composer path repository pointing
 *   at <checkout>/src/Core (symlinked), so "edit DAL code -> dan run" needs no
 *   packaging step. Composer does not need to re-resolve working-tree changes;
 *   the symlink picks them up live.
 *
 * The exact skeleton shape (bin/console bootstrap, Flex recipes and bundle
 * registration) still needs verification against Shopware 6.5, 6.6 and 6.7.
 */
final class RuntimeFactory
{
    public function __construct(
        private readonly Path $runtimesDirectory,
        private readonly Path $probeBundlePath,
    ) {}

    public function create(Reference $reference, Identity $identity, OutputInterface $output): Runtime
    {
        $runtimeDirectoryName = preg_replace('/[^A-Za-z0-9._-]+/', '_', $identity->id)
            ?? throw new RuntimeException(sprintf('Could not derive a runtime directory name from implementation identity "%s".', $identity->id));
        $workingDirectory = $this->runtimesDirectory->join($runtimeDirectoryName);

        if (file_exists($workingDirectory->join('.dan-runtime')->toString())) {
            $output->writeln(sprintf('  Reusing DAL runtime for <info>%s</info> at %s', $identity->label, $workingDirectory->toString()));

            return new Runtime(workingDirectory: $workingDirectory, output: $output);
        }

        $output->writeln(sprintf('  Building DAL runtime for <info>%s</info> at %s', $identity->label, $workingDirectory->toString()));

        if (!is_dir($workingDirectory->toString()) && !mkdir($workingDirectory->toString(), 0o777, true) && !is_dir($workingDirectory->toString())) {
            throw new RuntimeException(sprintf('Could not create runtime directory "%s".', $workingDirectory->toString()));
        }

        $coreConstraint = $reference->type === ReferenceType::Release ? $reference->releaseConstraint() : '*';
        $this->composer(args: [
            'create-project',
            '--no-install',
            '--no-interaction',
            'shopware/skeleton',
            '.',
        ], cwd: $workingDirectory, output: $output);

        $this->composer(args: [
            'config',
            'repositories.dan-probe',
            'json',
            json_encode([
                'type' => 'path',
                'url' => $this->probeBundlePath->toString(),
                'options' => ['symlink' => true],
            ], \JSON_THROW_ON_ERROR),
        ], cwd: $workingDirectory, output: $output);
        $this->composer(args: [
            'config',
            'repositories.dan-lib',
            'json',
            json_encode([
                'type' => 'path',
                'url' => $this->probeBundlePath->parent()->join('lib')->toString(),
                'options' => ['symlink' => true],
            ], \JSON_THROW_ON_ERROR),
        ], cwd: $workingDirectory, output: $output);

        if ($reference->type === ReferenceType::Checkout) {
            $this->composer(args: [
                'config',
                'repositories.dal-under-test',
                'json',
                json_encode([
                    'type' => 'path',
                    'url' => $reference->checkoutPath()->join('src', 'Core')->toString(),
                    'options' => ['symlink' => true],
                ], \JSON_THROW_ON_ERROR),
            ], cwd: $workingDirectory, output: $output);
            $this->composer(args: [
                'config',
                'minimum-stability',
                'dev',
            ], cwd: $workingDirectory, output: $output);
            $this->composer(args: [
                'config',
                'prefer-stable',
                'true',
            ], cwd: $workingDirectory, output: $output);
        }

        $this->composer(args: [
            'require',
            '--no-interaction',
            '--no-scripts',
            'shopware/core:' . $coreConstraint,
            'dan/probe:*',
        ], cwd: $workingDirectory, output: $output);

        $this->registerProbeBundle($workingDirectory);

        // The skeleton needs an APP_SECRET and related runtime settings;
        // DATABASE_URL is injected separately for each measurement cell.
        file_put_contents($workingDirectory->join('.env.local')->toString(), implode("\n", [
            'APP_ENV=prod',
            'APP_SECRET=dan-not-a-secret',
            'APP_URL=http://localhost:8000',
            'LOCK_DSN=flock',
        ]) . "\n");

        touch($workingDirectory->join('.dan-runtime')->toString());

        return new Runtime(workingDirectory: $workingDirectory, output: $output);
    }

    private function registerProbeBundle(Path $workingDirectory): void
    {
        $bundlesFile = $workingDirectory->join('config', 'bundles.php');
        if (!file_exists($bundlesFile->toString())) {
            throw new RuntimeException(sprintf('Expected "%s" to exist after create-project - the skeleton shape has changed, adjust the runtime factory.', $bundlesFile->toString()));
        }
        $contents = (string) file_get_contents($bundlesFile->toString());
        if (str_contains($contents, 'DanProbeBundle')) {
            return;
        }
        $registration = "    Dan\\Probe\\DanProbeBundle::class => ['all' => true],\n];";
        file_put_contents($bundlesFile->toString(), str_replace('];', $registration, $contents));
    }

    /**
     * @param list<string> $args
     */
    private function composer(array $args, Path $cwd, OutputInterface $output): void
    {
        $process = new Process([
            'composer',
            ...$args,
        ], $cwd->toString(), timeout: null);
        $process->mustRun(function (string $type, string $buffer) use ($output): void {
            if ($output->isVerbose()) {
                $output->write($buffer);
            }
        });
    }
}
