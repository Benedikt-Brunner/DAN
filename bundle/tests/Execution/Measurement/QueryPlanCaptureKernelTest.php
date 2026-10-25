<?php

declare(strict_types=1);

namespace Dan\Probe\Tests\Execution\Measurement;

use Dan\Lib\Time\Duration;
use Dan\Probe\Execution\Measurement\QueryPlanCapture;
use Dan\Probe\Recorder\RecordedStatement;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Test\TestCaseBase\KernelLifecycleManager;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Trust layer 2 for plans: the engine behind the kernel connection really
 * returns both the estimated plan and its EXPLAIN ANALYZE output for a
 * statement bound the way the DAL binds it (binary UUIDs included), in
 * whichever dialect that engine speaks.
 *
 * Requires a running database (DATABASE_URL) and the bundle's dev
 * dependencies (`composer install` in bundle/).
 */
final class QueryPlanCaptureKernelTest extends TestCase
{
    protected function setUp(): void
    {
        if (($_SERVER['DATABASE_URL'] ?? $_ENV['DATABASE_URL'] ?? null) === null) {
            self::markTestSkipped('DATABASE_URL is not set - kernel integration tests need a database.');
        }
    }

    public function testCapturesThePlanAndItsAnalysisForABoundRead(): void
    {
        $connection = $this->connection();
        $version = $connection->fetchOne('SELECT VERSION()');
        self::assertIsString($version);
        $isMariaDb = stripos($version, 'MariaDB') !== false;

        $plans = (new QueryPlanCapture($connection))->captureAll([
            3 => new RecordedStatement(
                // Every installation has languages, so the engine really
                // executes a scan rather than short-circuiting on a constant.
                sql: 'SELECT `language`.`id` FROM `language` WHERE `language`.`id` <> ? ORDER BY `language`.`name`',
                params: [Uuid::fromHexToBytes(Defaults::LANGUAGE_SYSTEM)],
                duration: Duration::fromNs(1),
            ),
        ]);

        self::assertSame([3], array_keys($plans), 'Plans are keyed by the statement positions they describe.');
        $plan = $plans[3]->toArray();
        self::assertSame('captured', $plan['capture']);
        self::assertIsArray($plan['raw']);
        self::assertArrayHasKey('query_block', $plan['raw']);
        self::assertIsString($plan['analysis']);
        self::assertTrue(mb_check_encoding($plan['analysis'], 'UTF-8'), 'Bound binary constants must not leak invalid UTF-8 into the protocol.');
        // Actual measurements, not estimates: MySQL's tree reports "actual
        // time", MariaDB's JSON its r_* counters.
        self::assertStringContainsString($isMariaDb ? '"r_loops"' : 'actual time=', $plan['analysis']);
    }

    public function testRecordsWhyAStatementHasNoPlan(): void
    {
        $plans = (new QueryPlanCapture($this->connection()))->captureAll([
            new RecordedStatement(sql: 'UPDATE `product` SET `stock` = `stock`', params: null, duration: Duration::fromNs(1)),
            new RecordedStatement(sql: 'SELECT `missing` FROM `dan_no_such_table`', params: null, duration: Duration::fromNs(1)),
        ]);

        self::assertSame('unsupported', $plans[0]->toArray()['capture'], 'ANALYZE would execute the write.');
        self::assertSame('failed', $plans[1]->toArray()['capture']);
    }

    private function connection(): Connection
    {
        $connection = KernelLifecycleManager::getKernel()->getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }
}
