<?php

declare(strict_types=1);

namespace Dan\Probe\Execution\Measurement;

use Dan\Lib\Protocol\PlanCapture;
use Dan\Probe\Execution\Result\CapturedPlan;
use Dan\Probe\Recorder\RecordedStatement;
use Doctrine\DBAL\Connection;
use Throwable;

/**
 * Asks the engine for the plan of a recorded statement with EXPLAIN
 * FORMAT=JSON, bound to the parameter values the DAL actually used. Runs
 * only after timing, on the same connection, and never mutates data: only
 * read statements are explained at all.
 *
 * @api Symfony service instantiated by the dependency-injection container.
 */
final readonly class QueryPlanCapture
{
    public function __construct(
        private Connection $connection,
    ) {}

    public function capture(RecordedStatement $statement): CapturedPlan
    {
        if (!self::supports($statement->sql)) {
            return CapturedPlan::unsupported();
        }

        // The DAL binds positional parameters as a list and named ones by
        // name; both are what the engine expects to plan against.
        $params = [];
        foreach ($statement->params ?? [] as $key => $value) {
            if (is_string($key) || $key >= 0) {
                $params[$key] = $value;
            }
        }

        try {
            $raw = $this->connection->fetchOne('EXPLAIN FORMAT=JSON ' . $statement->sql, $params);
        } catch (Throwable) {
            return CapturedPlan::failed();
        }
        if (!is_string($raw)) {
            return CapturedPlan::failed();
        }
        $plan = self::decodePlan($raw);

        return $plan === null ? CapturedPlan::failed() : new CapturedPlan(capture: PlanCapture::Captured, plan: $plan);
    }

    /**
     * Only reads are explained: EXPLAIN of a write is harmless in itself,
     * but describing writes is not what a read-path profiler compares, and
     * DDL or transaction control cannot be explained at all.
     */
    public static function supports(string $sql): bool
    {
        return preg_match('/\A\s*(?:\(\s*)*(?:SELECT|WITH)\b/i', $sql) === 1;
    }

    /**
     * Engines print constant values verbatim inside plan strings: MariaDB
     * embeds binary parameters (UUIDs) as raw bytes, which makes its output
     * invalid JSON. Control characters and invalid UTF-8 are replaced before
     * decoding; the plan structure - what the comparison reads - survives.
     *
     * @return array<mixed>|null
     */
    public static function decodePlan(string $raw): ?array
    {
        $sanitized = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '?', $raw);
        if ($sanitized === null) {
            return null;
        }
        $decoded = json_decode($sanitized, true, 512, \JSON_INVALID_UTF8_SUBSTITUTE);

        return is_array($decoded) ? $decoded : null;
    }
}
