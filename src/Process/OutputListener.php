<?php

declare(strict_types=1);

namespace Dan\Harness\Process;

/**
 * Receives a process's combined stdout/stderr chunks as they are produced.
 */
interface OutputListener
{
    public function onOutput(string $data): void;
}
