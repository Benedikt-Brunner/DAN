<?php

declare(strict_types=1);

namespace Dan\Lib\Protocol;

/**
 * Whether a query plan was obtained for a recorded statement. Every
 * measurement captures plans after timing, never inside it: the estimated
 * plan (EXPLAIN FORMAT=JSON) and what it actually did (EXPLAIN ANALYZE).
 * Captured means both were obtained. A statement that must not be analyzed
 * (ANALYZE executes it - writes, DDL, transaction control) is Unsupported,
 * and an engine that refused or produced undecodable output is Failed - both
 * are recorded explicitly rather than silently leaving a plan out.
 */
enum PlanCapture: string
{
    case Captured = 'captured';
    case Unsupported = 'unsupported';
    case Failed = 'failed';
}
