<?php

declare(strict_types=1);

namespace Dan\Harness\Tests\Database;

use Dan\Harness\Process\OutputListener;
use Dan\Harness\Process\ProcessCommand;
use Dan\Harness\Process\ProcessRunner;

/**
 * Records every command and reports success, so the readiness probe passes
 * on its first attempt - unless a command summary is scripted to fail a
 * number of times first.
 */
final class RecordingProcessRunner implements ProcessRunner
{
    /** @var list<ProcessCommand> */
    public array $commands = [];

    /** @var array<string, int> command summary => remaining failures */
    public array $failuresBeforeSuccess = [];

    public function run(ProcessCommand $command): bool
    {
        $this->commands[] = $command;
        $summary = self::summarize($command);
        if (($this->failuresBeforeSuccess[$summary] ?? 0) > 0) {
            --$this->failuresBeforeSuccess[$summary];

            return false;
        }

        return true;
    }

    public function mustRun(ProcessCommand $command, ?OutputListener $outputListener = null): void
    {
        $this->commands[] = $command;
    }

    /** @return list<string> the docker subcommand of every recorded command */
    public function summaries(): array
    {
        return array_map(self::summarize(...), $this->commands);
    }

    private static function summarize(ProcessCommand $command): string
    {
        return implode(' ', array_slice($command->arguments, 0, $command->arguments[1] === 'volume' ? 3 : 2));
    }
}
