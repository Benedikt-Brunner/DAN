<?php

declare(strict_types=1);

namespace Dan\Harness\Tests\Process;

use Dan\Harness\Process\ProcessCommand;
use Dan\Harness\Process\SymfonyProcessRunner;
use Dan\Lib\Filesystem\Path;
use PHPUnit\Framework\TestCase;

final class SymfonyProcessRunnerTest extends TestCase
{
    private string $workDirectory;

    protected function setUp(): void
    {
        $this->workDirectory = sys_get_temp_dir() . '/dan process runner; ' . bin2hex(random_bytes(4));
        mkdir($this->workDirectory);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->workDirectory . '/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($this->workDirectory);
    }

    public function testStreamsInputAndOutputWithoutShellInterpretation(): void
    {
        $inputPath = Path::fromString($this->workDirectory . '/input; unsafe.sql');
        $outputPath = Path::fromString($this->workDirectory . '/output; unsafe.sql');
        file_put_contents($inputPath->toString(), 'select one');

        (new SymfonyProcessRunner())->mustRun(new ProcessCommand(
            arguments: [
                \PHP_BINARY,
                '-r',
                'fwrite(STDOUT, strtoupper(stream_get_contents(STDIN)));',
            ],
            timeout: null,
            inputPath: $inputPath,
            outputPath: $outputPath,
        ));

        self::assertSame('SELECT ONE', file_get_contents($outputPath->toString()));
    }

    public function testRunReportsAnUnsuccessfulExit(): void
    {
        $command = new ProcessCommand(
            arguments: [
                \PHP_BINARY,
                '-r',
                'exit(4);',
            ],
            timeout: null,
        );

        self::assertFalse((new SymfonyProcessRunner())->run($command));
    }
}
