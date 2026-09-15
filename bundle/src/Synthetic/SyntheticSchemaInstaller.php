<?php

declare(strict_types=1);

namespace Dan\Probe\Synthetic;

use Doctrine\DBAL\Connection;

/**
 * Installs probe-owned tables before deterministic data is written.
 *
 * DAN provisions disposable databases itself, so an idempotent installer is
 * a smaller and more version-tolerant boundary than Shopware's plugin
 * lifecycle. Dataset writes still go through the DAL under test.
 *
 * @api Symfony service instantiated by the dependency-injection container.
 */
final readonly class SyntheticSchemaInstaller
{
    public function __construct(private Connection $connection) {}

    private const TABLE = 'dan_synthetic_blob';

    public function install(): void
    {
        // Idempotent without issuing DDL when nothing is missing: in MySQL
        // every DDL statement commits implicitly, even a no-op CREATE TABLE
        // IF NOT EXISTS - which would break callers seeding inside a
        // transaction (the kernel tests do).
        if ($this->connection->fetchOne('SHOW TABLES LIKE :table', ['table' => self::TABLE]) !== false) {
            return;
        }

        $this->connection->executeStatement(<<<'SQL'
            CREATE TABLE `dan_synthetic_blob` (
                `id` BINARY(16) NOT NULL,
                `name` VARCHAR(255) NOT NULL,
                `payload` JSON NOT NULL,
                `rank` INT NOT NULL,
                `created_at` DATETIME(3) NOT NULL,
                `updated_at` DATETIME(3) NULL,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    }
}
