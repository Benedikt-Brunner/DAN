<?php

declare(strict_types=1);

namespace Dan\Probe\Recorder\Dbal;

use Dan\Probe\Recorder\QueryRecorder;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\DriverManager;
use SensitiveParameter;

/** @phpstan-import-type Params from DriverManager */
final class RecordingDriver extends AbstractDriverMiddleware
{
    public function __construct(Driver $driver, private readonly QueryRecorder $recorder)
    {
        parent::__construct($driver);
    }

    /** @phpstan-param Params $params */
    public function connect(#[SensitiveParameter] array $params): DriverConnection
    {
        return new RecordingConnection(
            connection: parent::connect($params),
            recorder: $this->recorder,
        );
    }
}
