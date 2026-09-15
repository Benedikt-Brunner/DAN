<?php

declare(strict_types=1);

namespace Dan\Probe\Tests\Execution\Measurement;

use Dan\Lib\Protocol\PlanCapture;
use Dan\Probe\Execution\Measurement\QueryPlanCapture;
use Dan\Probe\Execution\Result\CapturedPlan;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The pure parts of plan capture: which statements are explained at all,
 * and how the engines' not-quite-JSON is made decodable. Talking to a real
 * engine is the kernel tests' job.
 */
final class QueryPlanCaptureTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function statements(): iterable
    {
        yield 'select' => [
            'SELECT `product`.`id` FROM `product`',
            true,
        ];
        yield 'lowercase, leading whitespace' => [
            "\n  select 1",
            true,
        ];
        yield 'parenthesised union' => [
            '(SELECT 1) UNION (SELECT 2)',
            true,
        ];
        yield 'common table expression' => [
            'WITH ids AS (SELECT 1) SELECT * FROM ids',
            true,
        ];
        yield 'update' => [
            'UPDATE product SET stock = 1',
            false,
        ];
        yield 'insert select' => [
            'INSERT INTO t SELECT 1',
            false,
        ];
        yield 'transaction control' => [
            'START TRANSACTION',
            false,
        ];
        yield 'set' => [
            "SET NAMES 'utf8mb4'",
            false,
        ];
    }

    #[DataProvider('statements')]
    public function testOnlyReadsAreExplained(string $sql, bool $supported): void
    {
        self::assertSame($supported, QueryPlanCapture::supports($sql));
    }

    public function testDecodesWellFormedPlans(): void
    {
        $plan = QueryPlanCapture::decodePlan('{"query_block": {"select_id": 1, "table": {"table_name": "product"}}}');

        self::assertSame(['query_block' => [
            'select_id' => 1,
            'table' => ['table_name' => 'product'],
        ]], $plan);
    }

    public function testSanitizesBinaryConstantsMariaDbEmbedsVerbatim(): void
    {
        // A UUID bound as BINARY(16) shows up as raw bytes inside the
        // attached_condition string - control characters and invalid UTF-8
        // that no JSON decoder accepts.
        $raw = "{\"query_block\": {\"select_id\": 1, \"table\": {\"table_name\": \"product\", \"attached_condition\": \"product.version_id = '\x0f\xa9\x1c\xe3\xe9jK\xc2\xbeK\xd9\xceu,4%'\"}}}";

        $plan = QueryPlanCapture::decodePlan($raw);

        self::assertNotNull($plan);
        self::assertIsArray($plan['query_block']);
        self::assertIsArray($plan['query_block']['table']);
        self::assertSame('product', $plan['query_block']['table']['table_name']);
        self::assertIsString($plan['query_block']['table']['attached_condition']);
        self::assertStringStartsWith("product.version_id = '", $plan['query_block']['table']['attached_condition']);
    }

    public function testUndecodableOutputIsNoPlan(): void
    {
        self::assertNull(QueryPlanCapture::decodePlan('not json at all'));
        self::assertNull(QueryPlanCapture::decodePlan('"a bare string"'));
    }

    public function testACapturedPlanMustCarryItsObjectAndOtherOutcomesMustNot(): void
    {
        self::assertSame([
            'capture' => 'unsupported',
            'raw' => null,
        ], CapturedPlan::unsupported()->toArray());
        self::assertSame([
            'capture' => 'failed',
            'raw' => null,
        ], CapturedPlan::failed()->toArray());

        $this->expectException(InvalidArgumentException::class);
        new CapturedPlan(capture: PlanCapture::Captured, plan: null);
    }
}
