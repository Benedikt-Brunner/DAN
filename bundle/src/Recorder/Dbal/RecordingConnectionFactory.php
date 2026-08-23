<?php

declare(strict_types=1);

namespace Dan\Probe\Recorder\Dbal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

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

        return DriverManager::getConnection($connection->getParams(), $configuration);
    }
}
