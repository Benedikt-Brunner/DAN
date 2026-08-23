<?php

declare(strict_types=1);

namespace Dan\Harness\Console\Run;

use Dan\Harness\Comparison\RunComparator;
use Dan\Harness\Console\InputParser;
use Dan\Harness\Database\DockerDatabaseManager;
use Dan\Harness\Database\SnapshotCache;
use Dan\Harness\Gate\Policy;
use Dan\Harness\Implementation\Identity\IdentityResolver;
use Dan\Harness\Implementation\Reference\Reference;
use Dan\Harness\Implementation\Runtime\RuntimeFactory;
use Dan\Harness\Measurement\Execution\GridCellMeasurer;
use Dan\Harness\Measurement\Execution\SessionRun;
use Dan\Harness\Measurement\Scheduling\BlockScheduler;
use Dan\Harness\Measurement\Scheduling\RunSlot;
use Dan\Harness\Protocol\ProtocolResolver;
use Dan\Harness\Report\MarkdownReportRenderer;
use Dan\Harness\RunStore\Artifact\RunManifest;
use Dan\Harness\RunStore\Filesystem\RunDirectory;
use Dan\Harness\RunStore\Index\SqliteIndexer;
use Dan\Lib\Filesystem\AbsolutePath;
use Dan\Lib\Filesystem\Path;
use DateTimeImmutable;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Profiles one or two DAL implementations across the (scenario x tier x
 * database) grid. With two implementations, measurement blocks alternate
 * between them within this session (mirrored A/B ordering) so host-level
 * noise cancels, each implementation against its own isolated database
 * container - and a diff report is produced at the end.
 *
 * This command only parses flags and wires the session together; the actual
 * per-cell work lives in GridCellMeasurer.
 */
#[AsCommand(name: 'run', description: 'Profile one or two DAL implementations across the measurement grid.')]
final class RunCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('dal', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'DAL under test: path to a shopware/shopware checkout or a released version. Pass twice for an A/B run (first = baseline).')
            ->addOption('db', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Database target, e.g. "mysql:8.0" or "mariadb:11.4". Repeatable.')
            ->addOption('tier', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Dataset tier: S, M or L. Repeatable.')
            ->addOption('warmup', null, InputOption::VALUE_REQUIRED, 'Warmup iterations per implementation per cell (discarded).', '5')
            ->addOption('iterations', null, InputOption::VALUE_REQUIRED, 'Measured iterations per implementation per cell.', '30')
            ->addOption('blocks', null, InputOption::VALUE_REQUIRED, 'Number of alternating measurement blocks.', '4')
            ->addOption('filter', null, InputOption::VALUE_REQUIRED, 'Scenario name filter (substring match).')
            ->addOption('out', null, InputOption::VALUE_REQUIRED, 'Output directory for run sessions.', './runs')
            ->addOption('max-regression', null, InputOption::VALUE_REQUIRED, 'A/B runs: gate threshold in percent for median wall time regressions.', '15')
            ->addOption('fail-on-sql-change', null, InputOption::VALUE_NONE, 'A/B runs: fail the gate when generated SQL changed at all.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $options = (new InputParser())->runOptions($input);
        if (count($options->dalSpecs) < 1 || count($options->dalSpecs) > 2) {
            $output->writeln('<error>Pass --dal once (profile) or twice (A/B diff, first is the baseline).</error>');

            return Command::INVALID;
        }

        $protocol = (new ProtocolResolver())->resolve(
            databaseSpecs: $options->databaseSpecs,
            tiers: $options->tiers,
            warmupIterations: $options->warmupIterations,
            measuredIterations: $options->measuredIterations,
            blocks: $options->blocks,
            scenarioFilter: $options->scenarioFilter,
        );

        $outRoot = $options->outputDirectory;
        $sessionDir = $outRoot->join(sprintf('%s-%s', date('Ymd-His'), substr(bin2hex(random_bytes(3)), 0, 6)));

        $identityResolver = new IdentityResolver();
        $runtimeFactory = new RuntimeFactory(runtimesDirectory: $outRoot->join('.dan-runtimes')->toPath(), probeBundlePath: Path::fromString(dirname(__DIR__, 3))->join('bundle'));

        $slots = [
            RunSlot::Baseline,
            RunSlot::Candidate,
        ];
        /** @var list<SessionRun> $runs */
        $runs = [];
        foreach ($options->dalSpecs as $i => $spec) {
            $slot = $slots[$i];
            $reference = Reference::fromString($spec);
            $identity = $identityResolver->resolve($reference);

            $directory = new RunDirectory($sessionDir->join($slot->value)->toPath());
            $directory->initialize(new RunManifest(
                runId: $sessionDir->basename() . '-' . $slot->value,
                createdAt: new DateTimeImmutable(),
                implementationReferenceType: $reference->type,
                implementationReference: $reference->toString(),
                implementationIdentity: $identity,
                protocol: $protocol,
            ));
            $output->writeln(sprintf('Run %s: <info>%s</info>', $slot->value, $identity->label));

            $runs[] = new SessionRun(
                slot: $slot,
                identity: $identity,
                directory: $directory,
                runtime: $runtimeFactory->create(reference: $reference, identity: $identity, output: $output),
            );
        }

        $measurer = new GridCellMeasurer(
            databaseManager: new DockerDatabaseManager(),
            cache: new SnapshotCache($outRoot->join('.dan-cache', 'snapshots')->toPath()),
            scheduler: new BlockScheduler(),
            output: $output,
        );

        foreach ($protocol->tiers as $tier) {
            foreach ($protocol->databases as $database) {
                $measurer->measure(
                    tier: $tier,
                    database: $database,
                    protocol: $protocol,
                    runs: $runs,
                );
            }
        }

        $indexer = new SqliteIndexer();
        foreach ($runs as $run) {
            $indexer->index($run->directory);
        }

        if (count($runs) === 2) {
            $policy = new Policy(
                maxWallRegressionPct: $options->maxWallRegressionPct,
                failOnSqlChange: $options->failOnSqlChange,
            );

            return $this->compareAndReport(baseline: $runs[0]->directory, candidate: $runs[1]->directory, sessionDir: $sessionDir, policy: $policy, output: $output);
        }

        $output->writeln(sprintf('Profile recorded at <info>%s</info>', $runs[0]->directory->root->toString()));

        return Command::SUCCESS;
    }

    private function compareAndReport(
        RunDirectory $baseline,
        RunDirectory $candidate,
        AbsolutePath $sessionDir,
        Policy $policy,
        OutputInterface $output,
    ): int {
        $comparison = RunComparator::compare(baseline: $baseline, candidate: $candidate);

        $violations = $policy->evaluate($comparison->cells);

        $report = (new MarkdownReportRenderer())->render(comparison: $comparison, violations: $violations);
        $reportPath = $sessionDir->join('report.md');
        file_put_contents($reportPath->toString(), $report . "\n");

        $output->writeln('');
        $output->writeln($report);
        $output->writeln('');
        $output->writeln(sprintf('Report written to <info>%s</info>', $reportPath->toString()));

        if ($violations !== []) {
            $output->writeln(sprintf('<error>Gate failed with %d violation(s).</error>', count($violations)));

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
