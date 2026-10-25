<?php

declare(strict_types=1);

namespace Dan\Probe\Execution\Measurement;

/**
 * How the engine behind the kernel connection spells "execute this statement
 * and report what its plan actually did". MySQL prints EXPLAIN ANALYZE only
 * as a tree (8.0 and 8.4 refuse FORMAT=JSON for it); MariaDB has no EXPLAIN
 * ANALYZE at all and spells it ANALYZE, printing JSON. Neither engine accepts
 * the other's syntax, so the dialect is read from the server version.
 */
enum AnalyzeDialect
{
    case MySql;
    case MariaDb;

    public static function fromServerVersion(string $version): self
    {
        return stripos($version, 'MariaDB') !== false ? self::MariaDb : self::MySql;
    }

    public function analyze(string $sql): string
    {
        return match ($this) {
            self::MySql => 'EXPLAIN ANALYZE FORMAT=TREE ' . $sql,
            self::MariaDb => 'ANALYZE FORMAT=JSON ' . $sql,
        };
    }
}
