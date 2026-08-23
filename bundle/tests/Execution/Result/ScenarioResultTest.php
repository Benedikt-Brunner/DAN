<?php

declare(strict_types=1);

namespace Dan\Probe\Tests\Execution\Result;

use Dan\Lib\Filesystem\Path;
use Dan\Probe\Execution\Result\ScenarioResult;
use Dan\Probe\Execution\Result\ScenarioResultWriter;
use Dan\Probe\Execution\Result\StatementResult;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ScenarioResultTest extends TestCase
{
    public function testSerializesTheScenarioResultProtocol(): void
    {
        $result = new ScenarioResult(
            scenario: 'product.keyword/listing',
            entity: 'product',
            dalVersion: '6.6.0',
            iterations: 2,
            wallSamplesNs: [
                100,
                200,
            ],
            statements: [new StatementResult(
                index: 0,
                sql: 'SELECT 1',
                durationSamplesNs: [
                    10,
                    20,
                ],
                divergent: true,
            )],
        );

        self::assertSame([
            'schemaVersion' => 1,
            'scenario' => 'product.keyword/listing',
            'entity' => 'product',
            'dalVersion' => '6.6.0',
            'iterations' => 2,
            'wallNsSamples' => [
                100,
                200,
            ],
            'statements' => [
                [
                    'index' => 0,
                    'sql' => 'SELECT 1',
                    'durationsNsSamples' => [
                        10,
                        20,
                    ],
                    'divergent' => true,
                ],
            ],
        ], $result->toArray());
    }

    public function testWriterUsesASafeScenarioNameAndProtocolJson(): void
    {
        $result = new ScenarioResult(
            scenario: 'product.keyword/listing',
            entity: 'product',
            dalVersion: null,
            iterations: 0,
            wallSamplesNs: [],
            statements: [],
        );
        $directory = sys_get_temp_dir() . '/dan-scenario-result-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory));
        $file = $directory . '/product.keyword_listing.json';

        try {
            (new ScenarioResultWriter())->write(
                outputDirectory: Path::fromString($directory),
                result: $result,
            );

            self::assertFileExists($file);
            self::assertSame(
                json_encode(
                    $result->toArray(),
                    \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR,
                ) . "\n",
                file_get_contents($file),
            );
        } finally {
            if (is_file($file)) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    public function testWriterFailsLoudlyWhenTheOutputDirectoryDoesNotExist(): void
    {
        $result = new ScenarioResult(
            scenario: 'product.deep-read',
            entity: 'product',
            dalVersion: null,
            iterations: 0,
            wallSamplesNs: [],
            statements: [],
        );
        $missingDirectory = sys_get_temp_dir() . '/dan-scenario-result-missing-' . bin2hex(random_bytes(8));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Could not write the scenario result for "product.deep-read"');

        (new ScenarioResultWriter())->write(
            outputDirectory: Path::fromString($missingDirectory),
            result: $result,
        );
    }
}
