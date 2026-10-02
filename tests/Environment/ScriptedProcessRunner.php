<?php

declare(strict_types=1);

namespace Dan\Harness\Tests\Environment;

use Dan\Harness\Process\OutputListener;
use Dan\Harness\Process\ProcessCommand;
use Dan\Harness\Process\ProcessRunner;
use RuntimeException;

/**
 * Answers probe commands from a script keyed by their leading words: a
 * matching key succeeds and writes the scripted stdout to the command's
 * output file; anything else fails like an absent tool would.
 */
final class ScriptedProcessRunner implements ProcessRunner
{
    /** @var list<ProcessCommand> */
    public array $commands = [];

    /** Output of "docker image inspect" once a pull has happened. */
    public ?string $afterPull = null;

    private bool $pulled = false;

    /**
     * @param array<string, string> $script command prefix => stdout
     */
    public function __construct(
        private readonly array $script,
    ) {}

    public function run(ProcessCommand $command): bool
    {
        $this->commands[] = $command;
        $line = implode(' ', $command->arguments);
        if (str_starts_with($line, 'docker pull')) {
            $this->pulled = true;
        }
        if ($this->pulled && $this->afterPull !== null && str_starts_with($line, 'docker image inspect')) {
            $this->write(command: $command, output: $this->afterPull);

            return true;
        }
        foreach ($this->script as $prefix => $output) {
            if (str_starts_with($line, $prefix)) {
                $this->write(command: $command, output: $output);

                return true;
            }
        }

        return false;
    }

    public function mustRun(ProcessCommand $command, ?OutputListener $outputListener = null): void
    {
        if (!$this->run($command)) {
            throw new RuntimeException(sprintf('Unscripted command "%s".', implode(' ', $command->arguments)));
        }
    }

    private function write(ProcessCommand $command, string $output): void
    {
        if ($command->outputPath !== null) {
            file_put_contents($command->outputPath->toString(), $output);
        }
    }
}
