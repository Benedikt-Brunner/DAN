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

    public function dumpBinary(): string
    {
        return match ($this->target->engine) {
            Engine::MariaDb => 'mariadb-dump',
            Engine::MySql => 'mysqldump',
        };
    }
}
