<?php

declare(strict_types=1);

namespace Dan\Harness\Tests\Console;

use Dan\Harness\Console\InputParser;
use Dan\Harness\Console\Run\RunCommand;
use Dan\Harness\Console\Run\RunOptions;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function testParsesIntegerOptions(): void
    {
        $options = $this->runOptions(blockWarmup: '0', iterations: '12');

        self::assertSame(0, $options->blockWarmupIterations);
        self::assertSame(12, $options->measuredIterations);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonIntegerValues(): iterable
    {
        // Every one of these passes is_numeric(); an (int) cast would then
        // quietly turn 2.5 into 2 and 1e2 into 100 measured iterations.
        yield 'decimal' => ['2.5'];
        yield 'exponent' => ['1e2'];
        yield 'hexadecimal' => ['0x1A'];
        yield 'word' => ['many'];
        yield 'empty' => [''];
    }

    #[DataProvider('nonIntegerValues')]
    public function testRejectsNonIntegerValuesForIntegerOptions(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->runOptions(iterations: $value);
    }

    private function runOptions(string $out = './runs', ?string $blockWarmup = null, ?string $iterations = null): RunOptions
    {
        // The probe process runs with the runtime as working directory, so
        // the session output root must leave parsing as an absolute path.
        $input = new ArrayInput(
            array_filter([
                '--dal' => ['v6.6.10.22'],
                '--db' => ['mysql:8.0'],
                '--tier' => ['S'],
                '--out' => $out,
                '--block-warmup' => $blockWarmup,
                '--iterations' => $iterations,
            ], fn (mixed $option): bool => $option !== null),
            (new RunCommand())->getDefinition(),
        );

        return (new InputParser())->runOptions($input);
    }
}
