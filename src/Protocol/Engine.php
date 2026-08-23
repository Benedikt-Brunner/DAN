<?php

declare(strict_types=1);

namespace Dan\Harness\Protocol;

/**
 * Database engine of a measurement target. The protocol only cares about
 * identity; engine-specific concerns (Docker images, client binaries) live in
 * Dan\Harness\Database and match exhaustively on this enum.
 */
enum Engine: string
{
    case MySql = 'mysql';
    case MariaDb = 'mariadb';
}
