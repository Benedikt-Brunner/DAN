<?php

declare(strict_types=1);

namespace Dan\Harness\Tests\Console;

use Dan\Harness\Console\InputParser;
use Dan\Harness\Console\Run\RunCommand;
use Dan\Harness\Console\Run\RunOptions;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;

final class InputParserTest extends TestCase
{
    public function testResolvesARelativeOutputDirectoryAgainstTheWorkingDirectory(): void
    {
        $options = $this->runOptions(out: './runs');

        self::assertSame(getcwd() . \DIRECTORY_SEPARATOR . 'runs', $options->outputDirectory->toString());
    }

    public function testKeepsAnAbsoluteOutputDirectoryUntouched(): void
    {
        $options = $this->runOptions(out: '/somewhere/runs');

        self::assertSame('/somewhere/runs', $options->outputDirectory->toString());
    }

    private function runOptions(string $out): RunOptions
    {
        // The probe process runs with the runtime as working directory, so
        // the session output root must leave parsing as an absolute path.
        $input = new ArrayInput(
            [
                '--dal' => ['v6.6.10.22'],
                '--db' => ['mysql:8.0'],
                '--tier' => ['S'],
                '--out' => $out,
            ],
            (new RunCommand())->getDefinition(),
        );

        return (new InputParser())->runOptions($input);
    }
}
