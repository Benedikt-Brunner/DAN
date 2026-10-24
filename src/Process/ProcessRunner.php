<?php

declare(strict_types=1);

namespace Dan\Harness\Process;

interface ProcessRunner
{
    /**
     * Whether the command succeeded; a non-zero exit or an exceeded timeout
     * is reported as false, never thrown.
     */
    public function run(ProcessCommand $command): bool;

    public function mustRun(ProcessCommand $command, ?OutputListener $outputListener = null): void;
}
