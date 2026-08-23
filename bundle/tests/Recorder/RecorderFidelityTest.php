<?php

declare(strict_types=1);

namespace Dan\Probe\Tests\Recorder;

use Dan\Probe\Recorder\QueryRecorder;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Test\TestCaseBase\KernelLifecycleManager;

/**
 * Trust layer 2: "what DAN records is what the DAL actually did." A recorder
 * that drops or mangles statements poisons every profile ever recorded, so
 * fidelity is asserted against a real connection, not a mock.
 *
 * Requires a running database (DATABASE_URL) and the bundle's dev
 * dependencies (`composer install` in bundle/).
 */
final class RecorderFidelityTest extends TestCase
{
    protected function setUp(): void
    {
        if (($_SERVER['DATABASE_URL'] ?? $_ENV['DATABASE_URL'] ?? null) === null) {
            self::markTestSkipped('DATABASE_URL is not set - kernel integration tests need a database.');
        }
    }

    public function testRecordsEveryStatementInOrderWithPositiveDurations(): void
    {
        $connection = $this->connection();
        $recorder = $this->recorder();
        $recorder->start();

        try {
            $connection->fetchAllAssociative('SELECT 1');
            $connection->fetchAllAssociative('SELECT 2');
            $connection->fetchAllAssociative('SELECT 3');
        } finally {
            $recorder->stop();
        }

        $records = $recorder->drain();

        self::assertSame([
            'SELECT 1',
            'SELECT 2',
            'SELECT 3',
        ], array_map(fn ($record) => $record->sql, $records));
        foreach ($records as $record) {
            self::assertGreaterThan(0, $record->duration->toNsInt());
        }
    }

    public function testDrainResetsTheBuffer(): void
    {
        $connection = $this->connection();
        $recorder = $this->recorder();
        $recorder->start();

        try {
            $connection->fetchAllAssociative('SELECT 1');
            $recorder->drain();
        } finally {
            $recorder->stop();
        }

        self::assertSame([], $recorder->drain());
    }

    public function testCapturesParametersOfPreparedStatements(): void
    {
        $connection = $this->connection();
        $recorder = $this->recorder();
        $recorder->start();

        try {
            $connection->fetchAllAssociative('SELECT ? AS a, ? AS b', [
                7,
                'x',
            ]);
        } finally {
            $recorder->stop();
        }

        $records = $recorder->drain();
        self::assertCount(1, $records);
        self::assertSame([
            7,
            'x',
        ], $records[0]->params);
    }

    private function connection(): Connection
    {
        $connection = KernelLifecycleManager::getKernel()->getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }

    private function recorder(): QueryRecorder
    {
        $recorder = KernelLifecycleManager::getKernel()->getContainer()->get(QueryRecorder::class);
        self::assertInstanceOf(QueryRecorder::class, $recorder);

        return $recorder;
    }
}
