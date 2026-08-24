<?php

declare(strict_types=1);

namespace Dan\Harness\Tests\RunStore\Artifact;

use Dan\Harness\Protocol\DatabaseTarget;
use Dan\Harness\Protocol\Engine;
use Dan\Harness\RunStore\Artifact\CellResult;
use Dan\Lib\Protocol\Tier;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Harness side of the dan:execute contract fixture: the JSON the probe
 * writes must parse into a cell result without losing a field. The identical
 * fixture is produced by the probe's ScenarioResultContractTest, so changing
 * the payload shape on either side without touching the shared fixture
 * breaks a test in that package.
 */
final class CellResultContractTest extends TestCase
{
    public function testParsesTheContractFixtureLosslessly(): void
    {
        $cell = CellResult::fromDecodedScenarioArray(
            payload: self::fixture(),
            tier: Tier::S,
            database: new DatabaseTarget(engine: Engine::MySql, version: '8.0'),
        );

        self::assertSame('product.deep-read', $cell->scenario->toString());
        self::assertSame([
            1250000,
            1190000,
            1210000,
        ], $cell->wallSamples->toNsArray());

        self::assertCount(2, $cell->statements);
        self::assertSame(0, $cell->statements[0]->index);
        self::assertSame('SELECT `product`.`id` FROM `product` WHERE `product`.`id` IN (?, ?, ?)', $cell->statements[0]->sql);
        self::assertSame([
            420000,
            395000,
            402000,
        ], $cell->statements[0]->durationSamples->toNsArray());
        self::assertFalse($cell->statements[0]->divergent);
        self::assertSame(1, $cell->statements[1]->index);
        self::assertSame('SELECT `category`.`id`, `category`.`name` FROM `category` WHERE `category`.`id` = ?', $cell->statements[1]->sql);
        self::assertSame([
            310000,
            305000,
            322000,
        ], $cell->statements[1]->durationSamples->toNsArray());
        self::assertTrue($cell->statements[1]->divergent);
    }

    /**
     * @return array<mixed>
     */
    private static function fixture(): array
    {
        $path = dirname(__DIR__, 3) . '/bundle/tests/Fixtures/scenario-result.v1.json';
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
