<?php

declare(strict_types=1);

namespace Dan\Probe\Tests\Execution\Result;

use Dan\Probe\Execution\Result\ScenarioResult;
use Dan\Probe\Execution\Result\StatementResult;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Probe side of the dan:execute contract fixture: the JSON this package
 * writes for the harness. The identical fixture is parsed by the harness's
 * CellResultContractTest, so changing the payload shape on either side
 * without touching the shared fixture breaks a test in that package.
 */
final class ScenarioResultContractTest extends TestCase
{
    public function testScenarioResultSerializesExactlyToTheContractFixture(): void
    {
        $result = new ScenarioResult(
            scenario: 'product.deep-read',
            entity: 'product',
            dalVersion: 'v6.6.10.22',
            iterations: 3,
            wallSamplesNs: [
                1250000,
                1190000,
                1210000,
            ],
            statements: [
                new StatementResult(
                    index: 0,
                    sql: 'SELECT `product`.`id` FROM `product` WHERE `product`.`id` IN (?, ?, ?)',
                    durationSamplesNs: [
                        420000,
                        395000,
                        402000,
                    ],
                    divergent: false,
                ),
                new StatementResult(
                    index: 1,
                    sql: 'SELECT `category`.`id`, `category`.`name` FROM `category` WHERE `category`.`id` = ?',
                    durationSamplesNs: [
                        310000,
                        305000,
                        322000,
                    ],
                    divergent: true,
                ),
            ],
        );

        self::assertSame(self::fixture(), $result->toArray());
    }

    /**
     * @return array<mixed>
     */
    private static function fixture(): array
    {
        $path = dirname(__DIR__, 2) . '/Fixtures/scenario-result.v1.json';
        $content = file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException(sprintf('Could not read the contract fixture "%s".', $path));
        }
        $decoded = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new RuntimeException('The contract fixture must decode to an array.');
        }

        return $decoded;
    }
}
