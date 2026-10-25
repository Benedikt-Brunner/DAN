<?php

declare(strict_types=1);

namespace Dan\Probe\Execution\Measurement;

use Dan\Lib\Protocol\PlanCapture;
use Dan\Probe\Execution\Result\CapturedPlan;
use Dan\Probe\Recorder\RecordedStatement;
use Doctrine\DBAL\Connection;
use Throwable;

/**
 * Asks the engine for the plan of each recorded statement, bound to the
 * parameter values the DAL actually used: the optimizer's estimated plan
 * (EXPLAIN FORMAT=JSON) and what that plan actually did when executed once
 * more (EXPLAIN ANALYZE, in the engine's own dialect and output format).
 * Runs only after timing, on the same connection, and never mutates data:
 * only read statements are explained - ANALYZE executes what it explains.
 *
 * @api Symfony service instantiated by the dependency-injection container.
 */
final readonly class QueryPlanCapture
{
    public function __construct(
        private Connection $connection,
    ) {}

    /**
     * @param array<int, RecordedStatement> $statements by statement position
     *
     * @return array<int, CapturedPlan> by the same positions
     */
    public function captureAll(array $statements): array
    {
        $version = $this->connection->fetchOne('SELECT VERSION()');
        $dialect = AnalyzeDialect::fromServerVersion(is_string($version) ? $version : '');

        $plans = [];
        foreach ($statements as $index => $statement) {
            $plans[$index] = $this->capture(statement: $statement, dialect: $dialect);
        }

        return $plans;
    }

    private function capture(RecordedStatement $statement, AnalyzeDialect $dialect): CapturedPlan
    {
        if (!self::supports($statement->sql)) {
            return CapturedPlan::unsupported();
        }

        // The DAL binds positional parameters as a list and named ones by
        // name, each with its binding type; both are what the engine expects
        // to plan against - a value rebound as a string can plan differently
        // or not parse at all (LIMIT '10').
        $params = [];
        $types = [];
        foreach ($statement->params ?? [] as $key => $value) {
            if (is_string($key) || $key >= 0) {
                $params[$key] = $value;
                if (isset($statement->types[$key])) {
                    $types[$key] = $statement->types[$key];
                }
            }
        }

        try {
            $rawPlan = $this->connection->fetchOne('EXPLAIN FORMAT=JSON ' . $statement->sql, $params, $types);
            $rawAnalysis = $this->connection->fetchOne($dialect->analyze($statement->sql), $params, $types);
        } catch (Throwable) {
            return CapturedPlan::failed();
        }
        if (!is_string($rawPlan) || !is_string($rawAnalysis)) {
            return CapturedPlan::failed();
        }
        $plan = self::decodePlan($rawPlan);

        return $plan === null ? CapturedPlan::failed() : new CapturedPlan(
            capture: PlanCapture::Captured,
            plan: $plan,
            analysis: self::sanitize($rawAnalysis),
        );
    }

    /**
     * Only reads are explained: ANALYZE executes the statement, so explaining
     * a write would perform it; DDL or transaction control cannot be
     * explained at all.
     */
    public static function supports(string $sql): bool
    {
        return preg_match('/\A\s*(?:\(\s*)*(?:SELECT|WITH)\b/i', $sql) === 1;
    }

    /**
     * Engines print constant values verbatim inside plans, which makes
     * MariaDB's output invalid JSON in two ways. A binary parameter bound as a
     * string arrives as raw bytes: every control byte - tab, LF and CR
     * included, which JSON forbids inside strings just the same - becomes a
     * space, which JSON accepts both between tokens and inside strings. Bound
     * as binary it arrives as _binary'...' with \xBB escapes JSON does not
     * know: a backslash that starts no JSON escape is escaped itself. The
     * plan structure survives either way.
     *
     * @return array<mixed>|null
     */
    public static function decodePlan(string $raw): ?array
    {
        $escaped = preg_replace_callback(
            '/\\\\(.)/s',
            fn (array $escape): string => str_contains('"\\/bfnrtu', $escape[1]) ? $escape[0] : '\\\\' . $escape[1],
            (string) preg_replace('/[\x00-\x1F]/', ' ', $raw),
        );
        $decoded = json_decode(self::sanitize((string) $escaped), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * The same verbatim constants make the output invalid UTF-8, which no
     * JSON protocol can carry. Control characters other than tab and line
     * breaks - the layout of MySQL's tree - and invalid byte sequences are
     * replaced; the text itself survives.
     */
    public static function sanitize(string $raw): string
    {
        return mb_scrub((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '?', $raw), 'UTF-8');
    }
}
