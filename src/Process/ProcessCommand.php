<?php

declare(strict_types=1);

namespace Dan\Harness\Process;

use Dan\Lib\Filesystem\Path;
use Dan\Lib\Time\Duration;

final class ProcessCommand
{
    /**
     * @param non-empty-list<string> $arguments
     */
    public function __construct(
        public readonly array $arguments,
        public readonly ?Duration $timeout,
        public readonly ?Path $inputPath = null,
        public readonly ?Path $outputPath = null,
        public readonly ?Path $workingDirectory = null,
    ) {}
}
