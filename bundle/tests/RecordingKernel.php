<?php

declare(strict_types=1);

namespace Dan\Probe\Tests;

use Dan\Probe\Recorder\RecordingBootstrap;
use Shopware\Core\Framework\Adapter\Database\MySQLFactory;
use Shopware\Core\Kernel;

/**
 * Exists only to reach Kernel::$connection: arming it with the recording
 * middleware is the same wiring bin/dan-console applies in a measured
 * runtime, so the kernel tests exercise the production recording path.
 * Never instantiated - the static connection is shared across the Kernel
 * hierarchy, so Kernel::getConnection() serves the armed connection.
 */
final class RecordingKernel extends Kernel
{
    public static function armRecording(): void
    {
        self::$connection = MySQLFactory::create([RecordingBootstrap::middleware()]);
    }
}
