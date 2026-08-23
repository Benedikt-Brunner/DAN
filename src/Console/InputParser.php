<?php

declare(strict_types=1);

namespace Dan\Harness\Console;

use Dan\Harness\Console\Diff\DiffOptions;
use Dan\Harness\Console\Run\RunOptions;
use Dan\Lib\Filesystem\Path;
use InvalidArgumentException;
use Symfony\Component\Console\Input\InputInterface;

/**
 * The one place where symfony's mixed-typed console input is narrowed into
 * typed per-command options objects. Option names here correspond to the
 * definitions in each command's configure().
 */
final class InputParser
{
    public function runOptions(InputInterface $input): RunOptions
    {
        return new RunOptions(
            dalSpecs: $this->stringList(input: $input, name: 'dal'),
            databaseSpecs: $this->stringList(input: $input, name: 'db'),
            tiers: $this->stringList(input: $input, name: 'tier'),
            warmupIterations: $this->int(input: $input, name: 'warmup'),
            measuredIterations: $this->int(input: $input, name: 'iterations'),
            blocks: $this->int(input: $input, name: 'blocks'),
            scenarioFilter: $this->nullableString(input: $input, name: 'filter'),
            outputDirectory: $this->absolutePath($this->string(input: $input, name: 'out')),
            maxWallRegressionPct: $this->float(input: $input, name: 'max-regression'),
            failOnSqlChange: $this->bool(input: $input, name: 'fail-on-sql-change'),
        );
    }

    public function diffOptions(InputInterface $input): DiffOptions
    {
        return new DiffOptions(
            baseline: Path::fromString($this->stringArgument(input: $input, name: 'baseline')),
            candidate: Path::fromString($this->stringArgument(input: $input, name: 'candidate')),
            outputFile: ($outputFile = $this->nullableString(input: $input, name: 'out')) === null ? null : Path::fromString($outputFile),
            maxWallRegressionPct: $this->nullableFloat(input: $input, name: 'max-regression'),
            failOnSqlChange: $this->bool(input: $input, name: 'fail-on-sql-change'),
            allowProtocolMismatch: $this->bool(input: $input, name: 'allow-protocol-mismatch'),
        );
    }

    /**
     * The run output directory crosses process boundaries: probe invocations
     * run with the runtime as working directory, so a relative path would
     * silently resolve somewhere else there.
     */
    private function absolutePath(string $value): Path
    {
        if (str_starts_with($value, \DIRECTORY_SEPARATOR)) {
            return Path::fromString($value);
        }
        if (str_starts_with($value, '.' . \DIRECTORY_SEPARATOR)) {
            $value = substr($value, 2);
        }
        $cwd = getcwd();
        if ($cwd === false) {
            throw new InvalidArgumentException(sprintf('Cannot resolve the relative path "%s": the current working directory is unavailable.', $value));
        }

        return Path::fromString($cwd)->join($value);
    }

    /**
     * @return list<string>
     */
    private function stringList(InputInterface $input, string $name): array
    {
        $value = $input->getOption($name);
        if (!is_array($value)) {
            throw new InvalidArgumentException(sprintf('Option --%s must be repeatable.', $name));
        }
        $strings = [];
        foreach ($value as $element) {
            if (!is_string($element)) {
                throw new InvalidArgumentException(sprintf('Option --%s expects string values.', $name));
            }
            $strings[] = $element;
        }

        return $strings;
    }

    private function string(InputInterface $input, string $name): string
    {
        $value = $input->getOption($name);
        if (!is_string($value)) {
            throw new InvalidArgumentException(sprintf('Option --%s expects a value.', $name));
        }

        return $value;
    }

    private function nullableString(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);
        if ($value !== null && !is_string($value)) {
            throw new InvalidArgumentException(sprintf('Option --%s expects a string value.', $name));
        }

        return $value;
    }

    private function int(InputInterface $input, string $name): int
    {
        $value = $input->getOption($name);
        if (!is_numeric($value)) {
            throw new InvalidArgumentException(sprintf('Option --%s expects an integer.', $name));
        }

        return (int) $value;
    }

    private function nullableFloat(InputInterface $input, string $name): ?float
    {
        $value = $input->getOption($name);
        if ($value === null) {
            return null;
        }
        if (!is_numeric($value)) {
            throw new InvalidArgumentException(sprintf('Option --%s expects a number.', $name));
        }

        return (float) $value;
    }

    private function float(InputInterface $input, string $name): float
    {
        $value = $this->nullableFloat(input: $input, name: $name);
        if ($value === null) {
            throw new InvalidArgumentException(sprintf('Option --%s expects a number.', $name));
        }

        return $value;
    }

    private function bool(InputInterface $input, string $name): bool
    {
        return (bool) $input->getOption($name);
    }

    private function stringArgument(InputInterface $input, string $name): string
    {
        $value = $input->getArgument($name);
        if (!is_string($value)) {
            throw new InvalidArgumentException(sprintf('Argument %s expects a value.', $name));
        }

        return $value;
    }
}
