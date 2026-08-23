<?php

declare(strict_types=1);

namespace Dan\Probe\Execution\Command;

use Dan\Lib\Filesystem\AbsolutePath;
use Dan\Probe\Execution\Measurement\ScenarioMeasurer;
use Dan\Probe\Execution\Result\ScenarioResultWriter;
use Dan\Probe\Scenario\ScenarioRegistry;
use InvalidArgumentException;
use Shopware\Core\Framework\Context;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Console adapter for executing and recording corpus scenarios.
 */
#[AsCommand(name: 'dan:execute', description: 'Execute DAN corpus scenarios and record the SQL the DAL produces.')]
final class ExecuteScenariosCommand extends Command
{
    public function __construct(
        private readonly ScenarioRegistry $scenarios,
        private readonly ScenarioMeasurer $measurer,
        private readonly ScenarioResultWriter $resultWriter,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('iterations', null, InputOption::VALUE_REQUIRED, 'Measured iterations per scenario.', '10')
            ->addOption('warmup', null, InputOption::VALUE_REQUIRED, 'Warmup iterations per scenario (recorded SQL is kept, timings are discarded).', '0')
            ->addOption('filter', null, InputOption::VALUE_REQUIRED, 'Scenario name filter (substring match).')
            ->addOption('output-dir', null, InputOption::VALUE_REQUIRED, 'Absolute directory to write one JSON result file per scenario. Must be absolute: this command runs with the DAL runtime as working directory.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $iterations = $this->intOption(input: $input, name: 'iterations');
        $warmup = $this->intOption(input: $input, name: 'warmup');
        $outputDir = $input->getOption('output-dir');
        if (!is_string($outputDir)) {
            $output->writeln('<error>--output-dir is required.</error>');

            return Command::INVALID;
        }
        // A relative path would resolve against the runtime's working
        // directory instead of the harness's - reject it at the boundary.
        $outputDirectory = AbsolutePath::fromString($outputDir);
        $filter = $input->getOption('filter');
        $filter = is_string($filter) ? $filter : null;

        $context = Context::createDefaultContext();

        foreach ($this->scenarios->matching(filter: $filter) as $scenario) {
            $output->writeln(sprintf('Scenario <info>%s</info>', $scenario->name()));
            $result = $this->measurer->measure(
                scenario: $scenario,
                context: $context,
                warmup: $warmup,
                iterations: $iterations,
            );
            $this->resultWriter->write(outputDirectory: $outputDirectory, result: $result);
        }

        return Command::SUCCESS;
    }

    private function intOption(InputInterface $input, string $name): int
    {
        // Deliberately duplicates the harness's InputParser narrowing: the
        // probe must stay free of any dependency on the harness package.
        $value = $input->getOption($name);
        if (!is_numeric($value)) {
            throw new InvalidArgumentException(sprintf('Option --%s expects an integer.', $name));
        }

        return (int) $value;
    }
}
