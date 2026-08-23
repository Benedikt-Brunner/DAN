<?php

declare(strict_types=1);

namespace Dan\Harness\Process;

use RuntimeException;
use Symfony\Component\Process\Process;

final class SymfonyProcessRunner implements ProcessRunner
{
    public function run(ProcessCommand $command): bool
    {
        return $this->execute(command: $command, mustSucceed: false, outputListener: null);
    }

    public function mustRun(ProcessCommand $command, ?OutputListener $outputListener = null): void
    {
        $this->execute(command: $command, mustSucceed: true, outputListener: $outputListener);
    }

    private function execute(ProcessCommand $command, bool $mustSucceed, ?OutputListener $outputListener): bool
    {
        $input = null;
        $output = null;
        try {
            $input = $this->openInput($command);
            $output = $this->openOutput($command);
            $process = new Process($command->arguments, $command->workingDirectory?->toString());
            $process->setTimeout($command->timeout?->toSecondsFloat());
            if ($input !== null) {
                $process->setInput($input);
            }
            if ($mustSucceed) {
                $process->mustRun($this->outputCallback(output: $output, outputListener: $outputListener));
            } else {
                $process->run($this->outputCallback(output: $output, outputListener: $outputListener));
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
    private function outputCallback($output, ?OutputListener $outputListener): ?callable
    {
        if ($output === null && $outputListener === null) {
            return null;
        }

        return function (string $type, string $data) use ($output, $outputListener): void {
            $outputListener?->onOutput($data);
            if ($output !== null && $type === Process::OUT && fwrite($output, $data) === false) {
                throw new RuntimeException('Could not write process output.');
            }
        };
    }
}
