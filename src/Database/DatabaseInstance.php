<?php

declare(strict_types=1);

namespace Dan\Harness\Database;

use Dan\Harness\Protocol\DatabaseTarget;
use Dan\Harness\Protocol\Engine;

final class DatabaseInstance
{
    public function __construct(
        public readonly string $containerName,
        public readonly DatabaseTarget $target,
        public readonly int $hostPort,
    ) {}

    /**
     * The named Docker volume holding this instance's data directory. It
     * outlives the container: a clean stop plus an archive of the volume is
     * the snapshot, and a fresh volume restored from the archive is how a
     * cached dataset comes back in seconds instead of an index rebuild.
     */
    public function dataVolume(): string
    {
        return $this->containerName . '-data';
    }

    public function databaseUrl(): string
    {
        return sprintf('mysql://root:dan@127.0.0.1:%d/dan', $this->hostPort);
    }

    public function clientBinary(): string
    {
        // MariaDB images are dropping the mysql-named symlinks; use the native
        // client name per engine.
        return match ($this->target->engine) {
            Engine::MariaDb => 'mariadb',
            Engine::MySql => 'mysql',
        };
    }
}
