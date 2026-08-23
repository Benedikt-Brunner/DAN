<?php

declare(strict_types=1);

namespace Dan\Harness\Database;

use Dan\Harness\Implementation\Identity\Identity;
use Dan\Harness\Protocol\DatabaseTarget;
use Dan\Lib\Filesystem\Path;
use Dan\Lib\Protocol\Tier;
use RuntimeException;

/**
 * Cache of seeded dataset snapshots. Seeding through the DAL write API is
 * slow (hours for the L tier), thus grid cells load snapshots, seeding happens at most
 * once per (implementation identity x tier x engine x seeder version).
 *
 * Bump SEEDER_VERSION whenever the deterministic seeder's output changes -
 * it invalidates every cached snapshot.
 */
final class SnapshotCache
{
    public const SEEDER_VERSION = 2;

    public function __construct(private readonly Path $directory) {}

    public function key(Identity $identity, Tier $tier, DatabaseTarget $database): string
    {
        return substr(
            hash('sha256', implode('|', [
                'seeder-v' . self::SEEDER_VERSION,
                $identity->id,
                $tier->value,
                $database->id(),
            ])),
            0,
            16,
        ) . sprintf('--%s--%s', $tier->value, $database->id());
    }

    public function has(string $key): bool
    {
        return file_exists($this->path($key)->toString());
    }

    public function path(string $key): Path
    {
        if (!is_dir($this->directory->toString()) && !mkdir($this->directory->toString(), 0o777, true) && !is_dir($this->directory->toString())) {
            throw new RuntimeException(sprintf('Could not create snapshot cache directory "%s".', $this->directory->toString()));
        }

        return $this->directory->join($key . '.sql');
    }
}
