<?php

declare(strict_types=1);

namespace Dan\Harness\Process;

interface ProcessRunner
{
    public function run(ProcessCommand $command): bool;

    public function mustRun(ProcessCommand $command, ?OutputListener $outputListener = null): void;
}
