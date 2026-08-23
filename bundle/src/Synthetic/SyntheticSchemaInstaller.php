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

    public function install(): void
    {
        $this->connection->executeStatement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `dan_synthetic_blob` (
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
