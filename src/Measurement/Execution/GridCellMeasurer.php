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
use Dan\Harness\RunStore\Artifact\RecordedDataset;
use Dan\Lib\Filesystem\Path;
use Dan\Lib\Protocol\DatasetFingerprint;
use Dan\Lib\Protocol\Tier;
use RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Measures one grid cell (tier x database) for every implementation in the
 * session: starts one isolated database container per implementation,
 * restored from the cached data-directory snapshot or freshly seeded (and
 * then snapshotted), fingerprints every dataset and refuses to measure over
 * datasets the implementations did not seed identically, then executes the
 * scheduled measurement blocks
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
        /** @var array<string, DatasetFingerprint> $fingerprints */
        $fingerprints = [];

        try {
            // One isolated container per implementation - no shared caches.
            foreach ($runs as $run) {
                $slot = $run->slot->value;
                $containerName = sprintf('dan-%s-%s-%s', $slot, $tier->value, preg_replace('/[^a-z0-9]+/', '', $database->id()));

                $snapshotKey = $this->cache->key(identity: $run->identity, tier: $tier, database: $database);
                $snapshotPath = $this->cache->path($snapshotKey);
                if ($this->cache->has($snapshotKey)) {
                    $this->output->writeln(sprintf('  [%s] Restoring cached snapshot %s', $slot, $snapshotKey));
                    $instances[$slot] = $this->databaseManager->start(target: $database, containerName: $containerName, snapshot: $snapshotPath);
                } else {
                    $this->output->writeln(sprintf('  [%s] Snapshot cache miss - installing and seeding tier %s (this can take a while)', $slot, $tier->value));
                    $instances[$slot] = $this->databaseManager->start(target: $database, containerName: $containerName);
                    $run->runtime->installShopware($instances[$slot]);
                    $run->runtime->run(args: [
                        'dan:seed',
                        '--tier',
                        $tier->value,
                    ], database: $instances[$slot]);
                    $this->databaseManager->snapshot(instance: $instances[$slot], snapshot: $snapshotPath);
                }

                $fingerprints[$slot] = $this->fingerprintDataset(run: $run, instance: $instances[$slot], tier: $tier, database: $database);
            }
            $this->requireEquivalentDatasets(fingerprints: $fingerprints, tier: $tier, database: $database);

            $blocks = $this->scheduler->schedule(slots: array_map(
                fn (SessionRun $run): RunSlot => $run->slot,
                $runs,
            ), protocol: $protocol);
            foreach ($blocks as $block) {
                $slot = $block->slot->value;
                $run = $bySlot[$slot];

                $blockDir = $run->directory->root->join('blocks', sprintf('%s-%s-block%d', $tier->value, $database->id(), $block->blockIndex));
                if (!is_dir($blockDir->toString()) && !mkdir($blockDir->toString(), 0o777, true) && !is_dir($blockDir->toString())) {
                    throw new RuntimeException(sprintf('Could not create block directory "%s".', $blockDir->toString()));
                }

                $args = [
                    'dan:execute',
                    '--iterations',
                    (string) $block->iterations,
                    '--warmup',
                    (string) $block->warmupIterations,
                    '--output-dir',
                    $blockDir->toString(),
                ];
                if ($protocol->scenarioFilter !== null) {
                    $args[] = '--filter';
                    $args[] = $protocol->scenarioFilter;
                }
                if ($block->capturePlans) {
                    $args[] = '--capture-plans';
                }
                $run->runtime->run(args: $args, database: $instances[$slot]);

                foreach (glob($blockDir->join('*.json')->toString()) ?: [] as $scenarioFileValue) {
                    $scenarioFile = Path::fromString($scenarioFileValue);
                    $data = json_decode((string) file_get_contents($scenarioFile->toString()), true, 512, \JSON_THROW_ON_ERROR);
                    if (!is_array($data)) {
                        throw new RuntimeException(sprintf('Malformed scenario result "%s".', $scenarioFile->toString()));
                    }
                    $result = CellResult::fromDecodedScenarioArray(payload: $data, tier: $tier, database: $database, block: $block);
                    $run->directory->mergeIntoCell(id: new CellId(scenario: $result->scenario, tier: $tier, database: $database), result: $result);
                }
            }
        } finally {
            foreach ($instances as $instance) {
                $this->databaseManager->stop($instance);
            }
        }
    }

    /**
     * The logical fingerprint of the dataset the cell will be measured on,
     * computed by the implementation's own probe and recorded with the run.
     */
    private function fingerprintDataset(SessionRun $run, DatabaseInstance $instance, Tier $tier, DatabaseTarget $database): DatasetFingerprint
    {
        $datasetsDir = $run->directory->root->join('datasets');
        $fingerprintFile = $datasetsDir->join(sprintf('%s-%s.fingerprint.json', $tier->value, $database->id()));
        $run->runtime->run(args: [
            'dan:fingerprint',
            '--tier',
            $tier->value,
            '--output',
            $fingerprintFile->toString(),
        ], database: $instance);
        $data = json_decode((string) file_get_contents($fingerprintFile->toString()), true, 512, \JSON_THROW_ON_ERROR);
        unlink($fingerprintFile->toString());
        if (!is_array($data)) {
            throw new RuntimeException(sprintf('Malformed dataset fingerprint from run %s for tier %s on %s.', $run->slot->value, $tier->value, $database->id()));
        }
        $fingerprint = DatasetFingerprint::fromDecodedArray($data);
        $run->directory->writeDataset(new RecordedDataset(tier: $tier, database: $database, fingerprint: $fingerprint));
        $this->output->writeln(sprintf('  [%s] Dataset fingerprint recorded (%d aspects)', $run->slot->value, count($fingerprint->aspects)));

        return $fingerprint;
    }

    /**
     * Deterministic seed input does not prove the implementations wrote the
     * same rows: a DAL write change, a default, an indexer or a failed
     * association write makes the comparison operate on different data. That
     * is a failed cell with entity-level diagnostics, never a latency table.
     *
     * @param array<string, DatasetFingerprint> $fingerprints by slot
     */
    private function requireEquivalentDatasets(array $fingerprints, Tier $tier, DatabaseTarget $database): void
    {
        $reference = null;
        $referenceSlot = null;
        foreach ($fingerprints as $slot => $fingerprint) {
            if ($reference === null) {
                $reference = $fingerprint;
                $referenceSlot = $slot;

                continue;
            }
            $differences = $reference->differences($fingerprint);
            if ($differences !== []) {
                throw new RuntimeException(sprintf('The %s and %s datasets for tier %s on %s are not logically equivalent - measuring would compare different data. %s', $referenceSlot, $slot, $tier->value, $database->id(), implode('; ', $differences)));
            }
        }
        if (count($fingerprints) > 1) {
            $this->output->writeln('  Datasets are logically equivalent across implementations');
        }
    }
}
