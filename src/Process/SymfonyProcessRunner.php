<?php

declare(strict_types=1);

namespace Dan\Harness\Process;

use RuntimeException;
use Symfony\Component\Process\Process;

final class SymfonyProcessRunner implements ProcessRunner
{
    public function run(ProcessCommand $command): bool
    {
        return $this->execute(command: $command, mustSucceed: false);
    }

    public function mustRun(ProcessCommand $command): void
    {
        $this->execute(command: $command, mustSucceed: true);
    }

    private function execute(ProcessCommand $command, bool $mustSucceed): bool
    {
        $input = null;
        $output = null;
        try {
            $input = $this->openInput($command);
            $output = $this->openOutput($command);
            $process = new Process($command->arguments);
            $process->setTimeout($command->timeout?->toSecondsFloat());
            if ($input !== null) {
                $process->setInput($input);
            }
            if ($mustSucceed) {
                $process->mustRun($this->outputCallback($output));
            } else {
                $process->run($this->outputCallback($output));
            }

            return $process->isSuccessful();
        } finally {
            if ($input !== null) {
                fclose($input);
            }
            if ($output !== null) {
                fclose($output);
            }
        }
    }

    /** @return resource|null */
    private function openInput(ProcessCommand $command)
    {
        if ($command->inputPath === null) {
            return null;
        }
        $input = fopen($command->inputPath->toString(), 'r');
        if ($input === false) {
            throw new RuntimeException(sprintf('Could not open process input file "%s".', $command->inputPath->toString()));
        }

        return $input;
    }

    /** @return resource|null */
    private function openOutput(ProcessCommand $command)
    {
        if ($command->outputPath === null) {
            return null;
        }
        $output = fopen($command->outputPath->toString(), 'w');
        if ($output === false) {
            throw new RuntimeException(sprintf('Could not open process output file "%s".', $command->outputPath->toString()));
        }

        return $output;
    }

    /**
     * @param resource|null $output
     *
     * @return (callable(string, string): void)|null
     */
    private function outputCallback($output): ?callable
    {
        if ($output === null) {
            return null;
        }

        return function (string $type, string $data) use ($output): void {
            if ($type === Process::OUT && fwrite($output, $data) === false) {
                throw new RuntimeException('Could not write process output.');
            }
        };
    }
}
