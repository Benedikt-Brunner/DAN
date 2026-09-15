<?php

declare(strict_types=1);

namespace Dan\Lib\Protocol;

/**
 * Whether a query plan was obtained for a recorded statement. Plans are
 * captured with EXPLAIN after timing, never inside it; a statement EXPLAIN
 * cannot describe (writes, DDL, transaction control) is Unsupported, and an
 * engine that refused or produced undecodable output is Failed - both are
 * recorded explicitly rather than silently leaving a plan out.
 */
enum PlanCapture: string
{
    case Captured = 'captured';
    case Unsupported = 'unsupported';
    case Failed = 'failed';
}
