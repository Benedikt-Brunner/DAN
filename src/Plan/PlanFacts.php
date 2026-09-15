<?php

declare(strict_types=1);

namespace Dan\Harness\Plan;

use Dan\Harness\Protocol\Engine;

/**
 * The comparable facts of a query plan: how each table is accessed and
 * whether the engine needs a temporary table or a filesort. Read from each
 * engine's own EXPLAIN FORMAT=JSON layout - MySQL and MariaDB are separate
 * readers, tested against captured output of each, and nothing here pretends
 * their raw plans are structurally the same document.
 */
final class PlanFacts
{
    /**
     * Row estimates that differ by less than this factor are noise in the
     * optimizer's statistics, not a plan change.
     */
    private const float MATERIAL_ROW_FACTOR = 2.0;

    /**
     * @param list<TableAccess> $tables in plan order
     */
    public function __construct(
        public readonly array $tables,
        public readonly bool $usesTemporaryTable,
        public readonly bool $usesFilesort,
    ) {}

    /**
     * @param array<mixed> $raw
     */
    public static function fromRaw(Engine $engine, array $raw): self
    {
        return match ($engine) {
            Engine::MySql => self::fromMySql($raw),
            Engine::MariaDb => self::fromMariaDb($raw),
        };
    }

    /**
     * MySQL: every "table" object names its access_type, key and
     * rows_examined_per_scan; ordering_operation / grouping_operation carry
     * boolean using_temporary_table and using_filesort flags.
     *
     * @param array<mixed> $raw
     */
    public static function fromMySql(array $raw): self
    {
        $tables = [];
        $temporary = false;
        $filesort = false;
        self::walk(node: $raw, visit: function (array $node) use (&$tables, &$temporary, &$filesort): void {
            $temporary = $temporary || ($node['using_temporary_table'] ?? null) === true;
            $filesort = $filesort || ($node['using_filesort'] ?? null) === true;
            $table = $node['table'] ?? null;
            if (is_array($table) && is_string($table['table_name'] ?? null)) {
                $tables[] = self::tableAccess(node: $table, rowsKey: 'rows_examined_per_scan');
            }
        });

        return new self(tables: $tables, usesTemporaryTable: $temporary, usesFilesort: $filesort);
    }

    /**
     * MariaDB: "table" objects carry access_type, key and rows; a temporary
     * table or a filesort appears as a nested "temporary_table" / "filesort"
     * node wrapping the plan below it.
     *
     * @param array<mixed> $raw
     */
    public static function fromMariaDb(array $raw): self
    {
        $tables = [];
        $temporary = false;
        $filesort = false;
        self::walk(node: $raw, visit: function (array $node) use (&$tables, &$temporary, &$filesort): void {
            $temporary = $temporary || is_array($node['temporary_table'] ?? null);
            $filesort = $filesort || is_array($node['filesort'] ?? null);
            $table = $node['table'] ?? null;
            if (is_array($table) && is_string($table['table_name'] ?? null)) {
                $tables[] = self::tableAccess(node: $table, rowsKey: 'rows');
            }
        });

        return new self(tables: $tables, usesTemporaryTable: $temporary, usesFilesort: $filesort);
    }

    /**
     * What changed between this plan and another one for the same
     * statement, in words a reviewer can act on. Empty when the plans agree
     * on everything that matters.
     *
     * @return list<string>
     */
    public function materialChangesTo(self $other): array
    {
        $changes = [];
        $otherTables = [];
        foreach ($other->tables as $access) {
            $otherTables[$access->table] = $access;
        }
        foreach ($this->tables as $access) {
            $theirs = $otherTables[$access->table] ?? null;
            if ($theirs === null) {
                $changes[] = sprintf('table %s no longer accessed', $access->table);

                continue;
            }
            unset($otherTables[$access->table]);
            if ($access->accessType !== $theirs->accessType) {
                $changes[] = sprintf('%s: access %s -> %s', $access->table, $access->accessType, $theirs->accessType);
            }
            if ($access->key !== $theirs->key) {
                $changes[] = sprintf('%s: index %s -> %s', $access->table, $access->key ?? 'none', $theirs->key ?? 'none');
            }
            if (self::rowsDifferMaterially(mine: $access->estimatedRows, theirs: $theirs->estimatedRows)) {
                $changes[] = sprintf('%s: ~%s -> ~%s rows', $access->table, $access->estimatedRows ?? '?', $theirs->estimatedRows ?? '?');
            }
        }
        foreach ($otherTables as $access) {
            $changes[] = sprintf('table %s newly accessed', $access->table);
        }
        if ($this->usesTemporaryTable !== $other->usesTemporaryTable) {
            $changes[] = $other->usesTemporaryTable ? 'temporary table introduced' : 'temporary table gone';
        }
        if ($this->usesFilesort !== $other->usesFilesort) {
            $changes[] = $other->usesFilesort ? 'filesort introduced' : 'filesort gone';
        }

        return $changes;
    }

    public function describe(): string
    {
        $parts = array_map(fn (TableAccess $access): string => $access->describe(), $this->tables);
        if ($this->usesTemporaryTable) {
            $parts[] = 'temporary table';
        }
        if ($this->usesFilesort) {
            $parts[] = 'filesort';
        }

        return $parts === [] ? 'no table access' : implode('; ', $parts);
    }

    private static function rowsDifferMaterially(?int $mine, ?int $theirs): bool
    {
        if ($mine === null || $theirs === null) {
            return $mine !== $theirs;
        }
        $low = max(1, min($mine, $theirs));
        $high = max($mine, $theirs);

        return $high / $low >= self::MATERIAL_ROW_FACTOR;
    }

    /**
     * @param array<mixed> $node
     */
    private static function tableAccess(array $node, string $rowsKey): TableAccess
    {
        $key = $node['key'] ?? null;
        $rows = $node[$rowsKey] ?? null;
        $table = $node['table_name'] ?? null;

        return new TableAccess(
            table: is_string($table) ? $table : 'unknown',
            accessType: is_string($node['access_type'] ?? null) ? $node['access_type'] : 'unknown',
            key: is_string($key) ? $key : null,
            estimatedRows: is_int($rows) ? $rows : (is_float($rows) ? (int) round($rows) : null),
        );
    }

    /**
     * Depth-first over every object in the plan, parents before children.
     *
     * @param array<mixed> $node
     * @param callable(array<mixed>): void $visit
     */
    private static function walk(array $node, callable $visit): void
    {
        $visit($node);
        foreach ($node as $child) {
            if (is_array($child)) {
                self::walk(node: $child, visit: $visit);
            }
        }
    }
}
