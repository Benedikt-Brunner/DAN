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
        // name; both are what the engine expects to plan against.
        $params = [];
        foreach ($statement->params ?? [] as $key => $value) {
            if (is_string($key) || $key >= 0) {
                $params[$key] = $value;
            }
        }

        try {
            $rawPlan = $this->connection->fetchOne('EXPLAIN FORMAT=JSON ' . $statement->sql, $params);
            $rawAnalysis = $this->connection->fetchOne($dialect->analyze($statement->sql), $params);
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
     * @return array<mixed>|null
     */
    public static function decodePlan(string $raw): ?array
    {
        $decoded = json_decode(self::sanitize($raw), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Engines print constant values verbatim inside plans: MariaDB embeds
     * binary parameters (UUIDs) as raw bytes, which makes its output invalid
     * JSON and invalid UTF-8. Control characters and invalid byte sequences
     * are replaced; the plan itself - what anyone reads - survives.
     */
    public static function sanitize(string $raw): string
    {
        return mb_scrub((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '?', $raw), 'UTF-8');
    }
}
