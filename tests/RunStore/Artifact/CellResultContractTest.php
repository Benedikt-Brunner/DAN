<?php

declare(strict_types=1);

namespace Dan\Harness\Tests\RunStore\Artifact;

use Dan\Harness\Measurement\Scheduling\MeasurementBlock;
use Dan\Harness\Measurement\Scheduling\RunSlot;
use Dan\Harness\Protocol\DatabaseTarget;
use Dan\Harness\Protocol\Engine;
use Dan\Harness\RunStore\Artifact\CellResult;
use Dan\Lib\Protocol\PlanCapture;
use Dan\Lib\Protocol\StatementDivergence;
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
            block: new MeasurementBlock(slot: RunSlot::Candidate, warmupIterations: 1, iterations: 3, blockIndex: 2, executionOrder: 5),
        );

        self::assertSame('product.deep-read', $cell->scenario->toString());
        self::assertCount(1, $cell->blocks);
        self::assertSame(2, $cell->blocks[0]->blockIndex);
        self::assertSame(5, $cell->blocks[0]->executionOrder);
        self::assertSame(1, $cell->blocks[0]->warmupIterations);
        self::assertSame(3, $cell->blocks[0]->iterations());
        self::assertSame([
            '0190d3d0a1b74a1c9f0e7b2c6d5e4f30',
            '0190d3d0a1b74a1c9f0e7b2c6d5e4f31',
        ], $cell->resultSet()->ids);
        self::assertSame(2, $cell->resultSet()->total);
        self::assertTrue($cell->resultSetConsistent());
        self::assertSame([
            1250000,
            1190000,
            1210000,
        ], $cell->wallSamples()->toNsArray());

        $statements = $cell->statements();
        self::assertCount(2, $statements);
        self::assertSame(0, $statements[0]->index);
        self::assertSame('SELECT `product`.`id` FROM `product` WHERE `product`.`id` IN (?, ?, ?)', $statements[0]->sql);
        self::assertSame([
            420000,
            395000,
            402000,
        ], $statements[0]->durationSamples->toNsArray());
        self::assertSame(3, $statements[0]->observed);
        self::assertSame(StatementDivergence::None, $statements[0]->divergence);
        self::assertSame(PlanCapture::Captured, $statements[0]->plan->capture);
        self::assertSame([
            'query_block' => [
                'select_id' => 1,
                'table' => [
                    'table_name' => 'product',
                    'access_type' => 'range',
                    'key' => 'PRIMARY',
                    'rows_examined_per_scan' => 3,
                ],
            ],
        ], $statements[0]->plan->raw);
        self::assertSame("-> Filter: (`product`.id in (?,?,?))  (cost=1.86 rows=3) (actual time=0.0213..0.0301 rows=2 loops=1)\n    -> Covering index range scan on product using PRIMARY over (id = ?) OR (id = ?) OR (id = ?)  (cost=1.86 rows=3) (actual time=0.0198..0.0279 rows=2 loops=1)\n", $statements[0]->plan->analysis);
        self::assertSame(1, $statements[1]->index);
        self::assertSame('SELECT `category`.`id`, `category`.`name` FROM `category` WHERE `category`.`id` = ?', $statements[1]->sql);
        self::assertSame([
            310000,
            305000,
            322000,
        ], $statements[1]->durationSamples->toNsArray());
        self::assertSame(2, $statements[1]->observed);
        self::assertSame(StatementDivergence::TextAndPresence, $statements[1]->divergence);
        self::assertSame(PlanCapture::Unsupported, $statements[1]->plan->capture);
        self::assertNull($statements[1]->plan->raw);
        self::assertNull($statements[1]->plan->analysis);
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
