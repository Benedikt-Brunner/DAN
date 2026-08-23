<?php

declare(strict_types=1);

namespace Dan\Harness\Console\Diff;

use Dan\Harness\Comparison\RunComparator;
use Dan\Harness\Console\InputParser;
use Dan\Harness\Gate\Policy;
use Dan\Harness\Report\MarkdownReportRenderer;
use Dan\Harness\RunStore\Filesystem\RunDirectory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Diffs two stored run profiles. SQL and statement-structure comparisons are
 * always valid across runs; latency comparisons are only trustworthy when
 * both runs were recorded in the same session on the same host (`dan run`
 * with two --dal values does that automatically). Latency gating is therefore
 * NOT applied here unless explicitly requested.
 */
#[AsCommand(name: 'diff', description: 'Diff two stored run profiles.')]
final class DiffCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('baseline', InputArgument::REQUIRED, 'Path to the baseline run directory.')
            ->addArgument('candidate', InputArgument::REQUIRED, 'Path to the candidate run directory.')
            ->addOption('out', null, InputOption::VALUE_REQUIRED, 'Write the markdown report to this file instead of stdout.')
            ->addOption('max-regression', null, InputOption::VALUE_REQUIRED, 'Gate threshold in percent for median wall time regressions. Only meaningful for same-session runs.')
            ->addOption('fail-on-sql-change', null, InputOption::VALUE_NONE, 'Fail when generated SQL changed at all.')
            ->addOption('allow-protocol-mismatch', null, InputOption::VALUE_NONE, 'Proceed even when the runs were recorded under different protocols.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $options = (new InputParser())->diffOptions($input);
        $baseline = new RunDirectory($options->baseline);
        $candidate = new RunDirectory($options->candidate);

        $comparison = RunComparator::compare(baseline: $baseline, candidate: $candidate);

        if (!$comparison->protocolsMatch && !$options->allowProtocolMismatch) {
            $output->writeln('<error>The two runs were recorded under different protocols - comparing them is not meaningful.</error>');
            $output->writeln('Pass --allow-protocol-mismatch to diff anyway (the report will carry a warning).');

            return Command::INVALID;
        }

        $policy = new Policy(
            maxWallRegressionPct: $options->maxWallRegressionPct,
            failOnSqlChange: $options->failOnSqlChange,
        );
        $violations = $policy->evaluate($comparison->cells);

        $report = (new MarkdownReportRenderer())->render(comparison: $comparison, violations: $violations);

        if ($options->outputFile !== null) {
            file_put_contents($options->outputFile->toString(), $report . "\n");
            $output->writeln(sprintf('Report written to <info>%s</info>', $options->outputFile->toString()));
        } else {
            $output->writeln($report);
        }

        return $violations === [] ? Command::SUCCESS : Command::FAILURE;
    }
}
