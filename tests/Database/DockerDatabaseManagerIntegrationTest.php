<?php

declare(strict_types=1);

namespace Dan\Harness\Tests\Database;

use Dan\Harness\Database\DatabaseInstance;
use Dan\Harness\Database\DockerDatabaseManager;
use Dan\Harness\Process\ProcessCommand;
use Dan\Harness\Process\SymfonyProcessRunner;
use Dan\Harness\Protocol\DatabaseTarget;
use Dan\Harness\Protocol\Engine;
use Dan\Lib\Filesystem\Path;
use Dan\Lib\Time\Duration;
use Dan\Lib\Time\Timestamp;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The load-bearing claim behind data-directory snapshots, against a real
 * Docker engine: what was written before the snapshot is exactly what a
 * server restored from it serves. Opt-in via DAN_DOCKER_INTEGRATION=1 with
 * DAN_DOCKER_INTEGRATION_DIR pointing at a directory the Docker engine can
 * bind-mount (a path that exists under the same name on the engine's host).
 */
final class DockerDatabaseManagerIntegrationTest extends TestCase
{
    private const string ROOT_PASSWORD = 'dan';

    private SymfonyProcessRunner $runner;

    /** Null until setUp() decided to run; tearDown() also runs after a skip. */
    private ?string $snapshotDirectory = null;

    protected function setUp(): void
    {
        if (getenv('DAN_DOCKER_INTEGRATION') !== '1') {
            self::markTestSkipped('Set DAN_DOCKER_INTEGRATION=1 (and DAN_DOCKER_INTEGRATION_DIR) to run the Docker snapshot round trip.');
        }
        $directory = getenv('DAN_DOCKER_INTEGRATION_DIR');
        $this->snapshotDirectory = (is_string($directory) && $directory !== '' ? $directory : sys_get_temp_dir()) . '/dan-snapshot-' . bin2hex(random_bytes(4));
        if (!mkdir($this->snapshotDirectory, 0o777, true)) {
            throw new RuntimeException(sprintf('Could not create "%s".', $this->snapshotDirectory));
        }
        $this->runner = new SymfonyProcessRunner();
    }

    protected function tearDown(): void
    {
        if ($this->snapshotDirectory === null) {
            return;
        }
        foreach (glob($this->snapshotDirectory . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->snapshotDirectory)) {
            rmdir($this->snapshotDirectory);
        }
    }

    public function testARestoredServerServesExactlyWhatWasWrittenBeforeTheSnapshot(): void
    {
        $manager = new DockerDatabaseManager($this->runner);
        $target = new DatabaseTarget(engine: Engine::MySql, version: '8.0');
        $snapshot = Path::fromString($this->snapshotDirectory . '/fixture--S--mysql-8.0.tar.gz');
        $suffix = bin2hex(random_bytes(3));

        $seeded = $manager->start(target: $target, containerName: 'dan-it-seed-' . $suffix);
        try {
            $this->sql(instance: $seeded, statement: 'CREATE TABLE fixture (id INT PRIMARY KEY, payload VARCHAR(64), INDEX idx_payload (payload))');
            $this->sql(instance: $seeded, statement: 'INSERT INTO fixture SELECT seq, SHA1(seq) FROM (SELECT a.n + 10 * b.n + 100 * c.n + 1 AS seq FROM (SELECT 0 n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) a, (SELECT 0 n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) b, (SELECT 0 n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) c) seqs');
            $expected = $this->sql(instance: $seeded, statement: 'SELECT COUNT(*), SUM(id), MD5(GROUP_CONCAT(payload ORDER BY id)) FROM fixture');

            $snapshotStartedAt = Timestamp::now();
            $manager->snapshot(instance: $seeded, snapshot: $snapshot);
            $snapshotTook = $snapshotStartedAt->elapsed();

            self::assertFileExists($snapshot->toString());
            self::assertGreaterThan(0, filesize($snapshot->toString()));
            self::assertSame($expected, $this->sql(instance: $seeded, statement: 'SELECT COUNT(*), SUM(id), MD5(GROUP_CONCAT(payload ORDER BY id)) FROM fixture'), 'The seeded server keeps serving after the snapshot.');
        } finally {
            $manager->stop($seeded);
        }

        $restoreStartedAt = Timestamp::now();
        $restored = $manager->start(target: $target, containerName: 'dan-it-restore-' . $suffix, snapshot: $snapshot);
        $restoreTook = $restoreStartedAt->elapsed();
        try {
            self::assertSame($expected, $this->sql(instance: $restored, statement: 'SELECT COUNT(*), SUM(id), MD5(GROUP_CONCAT(payload ORDER BY id)) FROM fixture'));
            self::assertStringContainsString('1000', $expected);
        } finally {
            $manager->stop($restored);
        }

        fwrite(\STDERR, sprintf("\nsnapshot: %.1fs, restore (incl. server start): %.1fs, archive: %d KiB\n", $snapshotTook->toSecondsFloat(), $restoreTook->toSecondsFloat(), (int) (filesize($snapshot->toString()) / 1024)));
    }

    private function sql(DatabaseInstance $instance, string $statement): string
    {
        $outputPath = Path::fromString($this->snapshotDirectory . '/query-' . bin2hex(random_bytes(4)) . '.txt');
        $this->runner->mustRun(new ProcessCommand(
            arguments: [
                'docker',
                'exec',
                '--',
                $instance->containerName,
                $instance->clientBinary(),
                '-uroot',
                '-p' . self::ROOT_PASSWORD,
                '--protocol=TCP',
                '--host=127.0.0.1',
                '--batch',
                '--skip-column-names',
                '--execute',
                $statement,
                'dan',
            ],
            timeout: Duration::fromSeconds(120),
            outputPath: $outputPath,
        ));
        $output = (string) file_get_contents($outputPath->toString());
        unlink($outputPath->toString());

        return trim($output);
    }
}
