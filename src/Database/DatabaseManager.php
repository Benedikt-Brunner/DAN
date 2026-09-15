<?php

declare(strict_types=1);

namespace Dan\Harness\Database;

use Dan\Harness\Protocol\DatabaseTarget;
use Dan\Lib\Filesystem\Path;

interface DatabaseManager
{
    /**
     * Starts an isolated database: empty, or restored from a data-directory
     * snapshot taken by snapshot(). A restored server starts with a cold
     * buffer pool - the per-cell warmup exists to bring it up to temperature.
     */
    public function start(DatabaseTarget $target, string $containerName, ?Path $snapshot = null): DatabaseInstance;

    public function stop(DatabaseInstance $instance): void;

    /**
     * Cleanly shuts the server down, archives its data directory into the
     * snapshot file and brings the same instance back up. Restoring the
     * archive costs a copy, not an index rebuild.
     */
    public function snapshot(DatabaseInstance $instance, Path $snapshot): void;
}
