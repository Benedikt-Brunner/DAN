<?php

declare(strict_types=1);

namespace Dan\Harness\Console;

use Dan\Harness\Process\OutputListener;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Streams process output to the console, but only when the user asked for
 * verbose output.
 */
final class VerboseProcessOutputListener implements OutputListener
{
    public function __construct(private readonly OutputInterface $output) {}

    public function onOutput(string $data): void
    {
        if ($this->output->isVerbose()) {
            $this->output->write($data);
        }
    }
}
