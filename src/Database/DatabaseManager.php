<?php

declare(strict_types=1);

namespace Dan\Harness\Database;

use Dan\Harness\Protocol\DatabaseTarget;
use Dan\Lib\Filesystem\Path;

interface DatabaseManager
{
    public function start(DatabaseTarget $target, string $containerName): DatabaseInstance;

    public function stop(DatabaseInstance $instance): void;

    public function importDump(DatabaseInstance $instance, Path $dumpPath): void;

    public function dumpTo(DatabaseInstance $instance, Path $dumpPath): void;
}
