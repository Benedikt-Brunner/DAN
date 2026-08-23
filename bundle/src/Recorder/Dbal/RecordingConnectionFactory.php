<?php

declare(strict_types=1);

namespace Dan\Probe\Recorder\Dbal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;

/** @api Factory invoked by the Symfony dependency-injection container. */
final class RecordingConnectionFactory
{
    public function create(Connection $connection, RecordingMiddleware $middleware): Connection
    {
        $configuration = clone $connection->getConfiguration();
        $configuration->setMiddlewares([
            ...$configuration->getMiddlewares(),
            $middleware,
        ]);

        $recordingConnection = DriverManager::getConnection($connection->getParams(), $configuration);

        // Shopware sets its session variables on the static kernel connection
        // (Kernel::initializeDatabaseConnectionVariables()), not on this fresh
        // session. Without the sql_mode adjustment, stock MySQL rejects
        // Shopware's GROUP BY queries under ONLY_FULL_GROUP_BY.
        if ($recordingConnection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $recordingConnection->executeStatement('SET @@group_concat_max_len = CAST(IF(@@group_concat_max_len > 320000, @@group_concat_max_len, 320000) AS UNSIGNED)');
            $recordingConnection->executeStatement("SET sql_mode=(SELECT REPLACE(@@sql_mode,'ONLY_FULL_GROUP_BY',''))");
        }

        return $recordingConnection;
    }
}
