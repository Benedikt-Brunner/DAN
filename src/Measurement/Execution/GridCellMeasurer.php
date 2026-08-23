<?php

declare(strict_types=1);

namespace Dan\Harness\Measurement\Execution;

use Dan\Harness\Database\DatabaseInstance;
use Dan\Harness\Database\DatabaseManager;
use Dan\Harness\Database\SnapshotCache;
use Dan\Harness\Measurement\Scheduling\BlockScheduler;
use Dan\Harness\Measurement\Scheduling\RunSlot;
use Dan\Harness\Protocol\DatabaseTarget;
use Dan\Harness\Protocol\Protocol;
use Dan\Harness\RunStore\Artifact\CellId;
use Dan\Harness\RunStore\Artifact\CellResult;
use Dan\Lib\Filesystem\Path;
use Dan\Lib\Protocol\Tier;
use RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Measures one grid cell (tier x database) for every implementation in the
 * session: starts one isolated database container per implementation, loads
 * or seeds the dataset snapshot, executes the scheduled measurement blocks
 * through each runtime's dan:execute, and merges the per-block scenario results
 * into the run's cell artifacts.
 */
final class GridCellMeasurer
{
    public function __construct(
        private readonly DatabaseManager $databaseManager,
        private readonly SnapshotCache $cache,
        private readonly BlockScheduler $scheduler,
        private readonly OutputInterface $output,
    ) {}

    /**
     * @param list<SessionRun> $runs
     */
    public function measure(
        Tier $tier,
        DatabaseTarget $database,
        Protocol $protocol,
        array $runs,
    ): void {
        $this->output->writeln(sprintf('<comment>Grid cell: tier %s on %s</comment>', $tier->value, $database->id()));

        /** @var array<string, SessionRun> $bySlot */
        $bySlot = [];
        foreach ($runs as $run) {
            $bySlot[$run->slot->value] = $run;
        }

        /** @var array<string, DatabaseInstance> $instances */
        $instances = [];

        try {
            // One isolated container per implementation - no shared caches.
            foreach ($runs as $run) {
                $slot = $run->slot->value;
                $containerName = sprintf('dan-%s-%s-%s', $slot, $tier->value, preg_replace('/[^a-z0-9]+/', '', $database->id()));
                $instances[$slot] = $this->databaseManager->start(target: $database, containerName: $containerName);

                $snapshotKey = $this->cache->key(identity: $run->identity, tier: $tier, database: $database);
                if ($this->cache->has($snapshotKey)) {
                    $this->output->writeln(sprintf('  [%s] Loading cached snapshot %s', $slot, $snapshotKey));
                    $this->databaseManager->importDump(instance: $instances[$slot], dumpPath: $this->cache->path($snapshotKey));
                } else {
                    $this->output->writeln(sprintf('  [%s] Snapshot cache miss - installing and seeding tier %s (this can take a while)', $slot, $tier->value));
                    $run->runtime->installShopware($instances[$slot]);
                    $run->runtime->run(args: [
                        'dan:seed',
                        '--tier',
                        $tier->value,
                    ], database: $instances[$slot]);
                    $this->databaseManager->dumpTo(instance: $instances[$slot], dumpPath: $this->cache->path($snapshotKey));
                }
            }

            $blocks = $this->scheduler->schedule(slots: array_map(
                fn (SessionRun $run): RunSlot => $run->slot,
                $runs,
            ), totalIterations: $protocol->measuredIterations, blocks: $protocol->blocks);
            /** @var array<string, bool> $warmedUp */
            $warmedUp = [];
            foreach ($blocks as $block) {
                $slot = $block->slot->value;
                $run = $bySlot[$slot];
                $warmup = isset($warmedUp[$slot]) ? 0 : $protocol->warmupIterations;
                $warmedUp[$slot] = true;

                $blockDir = $run->directory->root->join('blocks', sprintf('%s-%s-block%d', $tier->value, $database->id(), $block->blockIndex));
                if (!is_dir($blockDir->toString()) && !mkdir($blockDir->toString(), 0o777, true) && !is_dir($blockDir->toString())) {
                    throw new RuntimeException(sprintf('Could not create block directory "%s".', $blockDir->toString()));
                }

                $args = [
                    'dan:execute',
                    '--iterations',
                    (string) $block->iterations,
                    '--warmup',
                    (string) $warmup,
                    '--output-dir',
                    $blockDir->toString(),
                ];
                if ($protocol->scenarioFilter !== null) {
                    $args[] = '--filter';
                    $args[] = $protocol->scenarioFilter;
                }
                $run->runtime->run(args: $args, database: $instances[$slot]);

                foreach (glob($blockDir->join('*.json')->toString()) ?: [] as $scenarioFileValue) {
                    $scenarioFile = Path::fromString($scenarioFileValue);
                    $data = json_decode((string) file_get_contents($scenarioFile->toString()), true, 512, \JSON_THROW_ON_ERROR);
                    if (!is_array($data)) {
                        throw new RuntimeException(sprintf('Malformed scenario result "%s".', $scenarioFile->toString()));
                    }
                    $result = CellResult::fromDecodedScenarioArray(payload: $data, tier: $tier, database: $database);
                    $run->directory->mergeIntoCell(id: new CellId(scenario: $result->scenario, tier: $tier, database: $database), result: $result);
                }
            }
        } finally {
            foreach ($instances as $instance) {
                $this->databaseManager->stop($instance);
            }
        }
    }
}
