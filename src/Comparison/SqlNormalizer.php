<?php

declare(strict_types=1);

namespace Dan\Harness\Comparison;

/**
 * Normalizes captured SQL so that diffs between two DAL implementations
 * reflect structural changes, not incidental ones. The recorder captures SQL
 * with placeholders (parameters are logged separately), so the main sources
 * of false diffs are whitespace and the arity of IN-list placeholder groups,
 * which varies with the ids returned by the previous statement.
 */
final class SqlNormalizer
{
    public static function normalize(string $sql): string
    {
        $sql = preg_replace('/\s+/', ' ', trim($sql));
        // IN (?, ?, ?) and IN (:p1, :p2) collapse to a fixed-arity marker.
        $sql = preg_replace('/\(\s*\?(?:\s*,\s*\?)+\s*\)/', '(?...)', (string) $sql);
        $sql = preg_replace('/\(\s*:[A-Za-z0-9_]+(?:\s*,\s*:[A-Za-z0-9_]+)+\s*\)/', '(:...)', (string) $sql);

        return (string) $sql;
    }
}
