<?php

declare(strict_types=1);

namespace Dan\Probe\Seeding\Command;

use Dan\Lib\Protocol\Tier;
use Dan\Probe\Seeding\Dataset\DatasetSeeder;
use Dan\Probe\Seeding\Dataset\TierSpec;
use Dan\Probe\Seeding\Progress\ConsoleSeedProgressReporter;
use Shopware\Core\Framework\Context;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Console adapter for deterministic dataset seeding.
 */
#[AsCommand(name: 'dan:seed', description: 'Seed the deterministic DAN dataset for a tier.')]
final class SeedTierCommand extends Command
{
    public function __construct(
        private readonly DatasetSeeder $seeder,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('tier', null, InputOption::VALUE_REQUIRED, 'Dataset tier: S, M or L.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $tier = $input->getOption('tier');
        if (!is_string($tier)) {
            $output->writeln('<error>--tier is required (S, M or L).</error>');

            return Command::INVALID;
        }
        $tier = Tier::tryFrom($tier);
        if ($tier === null) {
            $output->writeln('<error>Unknown tier; expected S, M or L.</error>');

            return Command::INVALID;
        }
        $this->seeder->seed(
            spec: TierSpec::forTier($tier),
            context: Context::createDefaultContext(),
            progress: new ConsoleSeedProgressReporter($output),
        );

        return Command::SUCCESS;
    }
}
