<?php

declare(strict_types=1);

namespace Dan\Probe\Seeding\Command;

use Dan\Lib\Filesystem\AbsolutePath;
use Dan\Lib\Protocol\Tier;
use Dan\Probe\Seeding\Fingerprint\DatasetFingerprinter;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Console adapter for the logical dataset fingerprint: the harness runs it
 * after every restore or seed and refuses to compare implementations over
 * datasets whose fingerprints differ.
 */
#[AsCommand(name: 'dan:fingerprint', description: 'Write the logical fingerprint of the seeded DAN dataset as JSON.')]
final class FingerprintDatasetCommand extends Command
{
    public function __construct(
        private readonly DatasetFingerprinter $fingerprinter,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('tier', null, InputOption::VALUE_REQUIRED, 'Dataset tier the database was seeded for: S, M or L.')
            ->addOption('output', null, InputOption::VALUE_REQUIRED, 'Absolute path of the JSON file to write. Must be absolute: this command runs with the DAL runtime as working directory.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $tier = $input->getOption('tier');
        $tier = is_string($tier) ? Tier::tryFrom($tier) : null;
        if ($tier === null) {
            $output->writeln('<error>--tier is required (S, M or L).</error>');

            return Command::INVALID;
        }
        $outputFile = $input->getOption('output');
        if (!is_string($outputFile)) {
            $output->writeln('<error>--output is required.</error>');

            return Command::INVALID;
        }
        $path = AbsolutePath::fromString($outputFile);

        $fingerprint = $this->fingerprinter->fingerprint($tier);
        $written = @file_put_contents(
            $path->toString(),
            json_encode($fingerprint->toArray(), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR) . "\n",
        );
        if ($written === false) {
            throw new RuntimeException(sprintf('Could not write the dataset fingerprint to "%s".', $path->toString()));
        }

        return Command::SUCCESS;
    }
}
