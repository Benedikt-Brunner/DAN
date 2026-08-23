<?php

declare(strict_types=1);

namespace Dan\Probe\Recorder;

use Dan\Probe\Recorder\Dbal\RecordingMiddleware;

/**
 * Hands the recorder across the pre-container boundary.
 *
 * The recording middleware must exist when the kernel connection is
 * constructed (KernelFactory::create()), before the dependency-injection
 * container does. This bootstrap owns the single QueryRecorder instance: the
 * DAN console entry (bin/dan-console) obtains the middleware from it, and the
 * container's QueryRecorder service resolves to the same instance via the
 * factory in services.yaml. The static handoff mirrors how Shopware itself
 * carries the kernel connection (Kernel::$connection).
 */
final class RecordingBootstrap
{
    private static ?QueryRecorder $recorder = null;

    private static bool $armed = false;

    public static function middleware(): RecordingMiddleware
    {
        self::$armed = true;

        return new RecordingMiddleware(self::recorder());
    }

    public static function recorder(): QueryRecorder
    {
        return self::$recorder ??= new QueryRecorder();
    }

    /**
     * Whether the kernel connection was built with the recording middleware.
     * False means the process booted through plain bin/console - measuring
     * there would silently record nothing.
     */
    public static function armed(): bool
    {
        return self::$armed;
    }
}
