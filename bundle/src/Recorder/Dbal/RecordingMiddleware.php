<?php

declare(strict_types=1);

namespace Dan\Probe\Recorder\Dbal;

use Dan\Probe\Recorder\QueryRecorder;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;

/** @api DBAL middleware instantiated by the Symfony dependency-injection container. */
final readonly class RecordingMiddleware implements Middleware
{
    public function __construct(private QueryRecorder $recorder) {}

    public function wrap(Driver $driver): Driver
    {
        return new RecordingDriver(driver: $driver, recorder: $this->recorder);
    }
}
