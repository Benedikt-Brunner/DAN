<?php

declare(strict_types=1);

namespace Dan\Harness\Tests\Plan;

use Dan\Harness\Plan\PlanFacts;
use Dan\Harness\Plan\TableAccess;
use Dan\Harness\Protocol\Engine;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Plan facts read from EXPLAIN FORMAT=JSON as MySQL 8.0 and MariaDB 11.4
 * really print it (fixtures captured from the official images against a
 * Shopware-shaped schema). The two layouts are tested separately: the same
 * query gets a different plan on each engine, and each engine spells its
 * plan differently.
 */
final class PlanFactsTest extends TestCase
{
    public function testMySqlSortedLeftJoinNeedsATemporaryTableAndAFilesort(): void
    {
        $facts = PlanFacts::fromRaw(engine: Engine::MySql, raw: self::fixture('mysql-join-sort'));

        self::assertSame([
            'range product via idx_stock (~900 rows)',
            'ALL product.tax (~3 rows)',
            'ref product.translation via PRIMARY (~1 rows)',
        ], self::describeTables($facts));
        self::assertTrue($facts->usesTemporaryTable);
        self::assertTrue($facts->usesFilesort);
    }

    public function testMariaDbServesTheSameSortedJoinFromTheIndex(): void
    {
        $facts = PlanFacts::fromRaw(engine: Engine::MariaDb, raw: self::fixture('mariadb-join-sort'));

        self::assertSame([
            'range product via idx_stock (~900 rows)',
            'eq_ref product.tax via PRIMARY (~1 rows)',
            'ref product.translation via PRIMARY (~1 rows)',
        ], self::describeTables($facts));
        self::assertFalse($facts->usesTemporaryTable);
        self::assertFalse($facts->usesFilesort);
    }

    public function testMySqlGroupingReportsItsTemporaryTableAndFilesortFlags(): void
    {
        $facts = PlanFacts::fromMySql(self::fixture('mysql-group-temp'));

        self::assertSame([
            'ALL product (~1000 rows)',
            'ref product_category via PRIMARY (~1 rows)',
        ], self::describeTables($facts));
        self::assertTrue($facts->usesTemporaryTable);
        self::assertTrue($facts->usesFilesort);
    }

    public function testMariaDbGroupingWrapsThePlanInFilesortAndTemporaryTableNodes(): void
    {
        $facts = PlanFacts::fromMariaDb(self::fixture('mariadb-group-temp'));

        self::assertSame([
            'index product_category via fk_category (~1000 rows)',
            'ref product via PRIMARY (~1 rows)',
        ], self::describeTables($facts));
        self::assertTrue($facts->usesTemporaryTable);
        self::assertTrue($facts->usesFilesort);
    }

    public function testPrimaryKeyLookupsReadTheSameOnBothEngines(): void
    {
        $mysql = PlanFacts::fromMySql(self::fixture('mysql-pk-in'));
        $mariadb = PlanFacts::fromMariaDb(self::fixture('mariadb-pk-in'));

        self::assertSame(['range product via PRIMARY (~2 rows)'], self::describeTables($mysql));
        self::assertSame(['range product via PRIMARY (~2 rows)'], self::describeTables($mariadb));
        self::assertSame([], $mysql->materialChangesTo($mariadb));
    }

    public function testMaterialChangesNameWhatAReviewerMustLookAt(): void
    {
        $lookup = PlanFacts::fromMySql(self::fixture('mysql-pk-in'));
        $scan = PlanFacts::fromMySql(self::fixture('mysql-group-temp'));

        self::assertSame([
            'product: access range -> ALL',
            'product: index PRIMARY -> none',
            'product: ~2 -> ~1000 rows',
            'table product_category newly accessed',
            'temporary table introduced',
            'filesort introduced',
        ], $lookup->materialChangesTo($scan));
        self::assertSame([
            'product: access ALL -> range',
            'product: index none -> PRIMARY',
            'product: ~1000 -> ~2 rows',
            'table product_category no longer accessed',
            'temporary table gone',
            'filesort gone',
        ], $scan->materialChangesTo($lookup));
    }

    public function testRowEstimatesWithinAFactorOfTwoAreNotMaterial(): void
    {
        $a = new PlanFacts(tables: [new TableAccess(table: 'product', accessType: 'ref', key: 'PRIMARY', estimatedRows: 100)], usesTemporaryTable: false, usesFilesort: false);
        $b = new PlanFacts(tables: [new TableAccess(table: 'product', accessType: 'ref', key: 'PRIMARY', estimatedRows: 190)], usesTemporaryTable: false, usesFilesort: false);
        $c = new PlanFacts(tables: [new TableAccess(table: 'product', accessType: 'ref', key: 'PRIMARY', estimatedRows: 200)], usesTemporaryTable: false, usesFilesort: false);

        self::assertSame([], $a->materialChangesTo($b));
        self::assertSame(['product: ~100 -> ~200 rows'], $a->materialChangesTo($c));
    }

    public function testDescribesAPlanWithoutTablesHonestly(): void
    {
        self::assertSame('no table access', PlanFacts::fromMySql(['query_block' => ['select_id' => 1]])->describe());
        self::assertSame(
            'range product via idx_stock (~900 rows); ALL product.tax (~3 rows); ref product.translation via PRIMARY (~1 rows); temporary table; filesort',
            PlanFacts::fromMySql(self::fixture('mysql-join-sort'))->describe(),
        );
    }

    /**
     * @return array<mixed>
     */
    private static function fixture(string $name): array
    {
        $path = __DIR__ . '/fixtures/' . $name . '.json';
        $content = file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException(sprintf('Could not read fixture "%s".', $path));
        }
        $decoded = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new RuntimeException('Plan fixtures must decode to objects.');
        }

        return $decoded;
    }

    /**
     * @return list<string>
     */
    private static function describeTables(PlanFacts $facts): array
    {
        return array_map(fn (TableAccess $access): string => $access->describe(), $facts->tables);
    }
}
