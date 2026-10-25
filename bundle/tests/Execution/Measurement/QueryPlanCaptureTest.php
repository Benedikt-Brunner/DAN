<?php

declare(strict_types=1);

namespace Dan\Probe\Tests\Execution\Measurement;

use Dan\Lib\Protocol\PlanCapture;
use Dan\Probe\Execution\Measurement\AnalyzeDialect;
use Dan\Probe\Execution\Measurement\QueryPlanCapture;
use Dan\Probe\Execution\Result\CapturedPlan;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The pure parts of plan capture: which statements are explained at all,
 * how each engine spells ANALYZE, and how the engines' not-quite-JSON is
 * made decodable. Talking to a real engine is QueryPlanCaptureKernelTest's job.
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

    /**
     * @return iterable<string, array{string}>
     */
    public static function binaryConstants(): iterable
    {
        yield 'control and invalid UTF-8 bytes' => ["\x0f\xa9\x1c\xe3\xe9jK\xc2\xbeK\xd9\xceu,4%"];
        yield 'tab, line feed and carriage return' => ["\x0f\x09\xa9\x0a\x1c\x0d\xe3jK"];
        // Bound as binary, MariaDB prints the literal with \xNN escapes.
        yield 'escaped binary literal' => ['_binary\'/\\xBB_\\xE2\\xE2\\x9AMp\\xAAXT\\xCE|\\xE3\\xE2\\x0B'];
    }

    #[DataProvider('binaryConstants')]
    public function testSanitizesBinaryConstantsMariaDbEmbedsVerbatim(string $constant): void
    {
        // A UUID bound as BINARY(16) shows up as raw bytes inside the
        // attached_condition string - control characters and invalid UTF-8
        // that no JSON decoder accepts. The plan around it is pretty-printed,
        // so its own line breaks must keep working as JSON whitespace.
        $raw = "{\n\t\"query_block\": {\"select_id\": 1, \"table\": {\"table_name\": \"product\", \"attached_condition\": \"product.version_id = '" . $constant . "'\"}}\r\n}";

        $plan = QueryPlanCapture::decodePlan($raw);

        self::assertNotNull($plan);
        self::assertIsArray($plan['query_block']);
        self::assertIsArray($plan['query_block']['table']);
        self::assertSame('product', $plan['query_block']['table']['table_name']);
        self::assertIsString($plan['query_block']['table']['attached_condition']);
        self::assertStringStartsWith("product.version_id = '", $plan['query_block']['table']['attached_condition']);
    }

    public function testSanitizesBinaryConstantsInsideAnalysisText(): void
    {
        // MariaDB's ANALYZE JSON and MySQL's tree print the same constants;
        // the analysis is kept as text, which must still be valid UTF-8 to
        // travel in the JSON protocol.
        $analysis = QueryPlanCapture::sanitize("-> Filter: (p.version_id = '\x0f\xa9\x1c\xe3jK')  (actual time=0.03..0.04 rows=3 loops=1)");

        self::assertTrue(mb_check_encoding($analysis, 'UTF-8'));
        self::assertStringStartsWith("-> Filter: (p.version_id = '?", $analysis);
        self::assertStringEndsWith("jK')  (actual time=0.03..0.04 rows=3 loops=1)", $analysis);
        self::assertNotFalse(json_encode($analysis));
    }

    public function testKeepsValidJsonEscapesIntact(): void
    {
        $plan = QueryPlanCapture::decodePlan('{"sort_key": "`a`.`b`", "attached_condition": "x = \'a\\\\xb\\"c\\u00e9\\n\'"}');

        self::assertSame([
            'sort_key' => '`a`.`b`',
            'attached_condition' => "x = 'a\\xb\"c\u{e9}\n'",
        ], $plan);
    }

    public function testUndecodableOutputIsNoPlan(): void
    {
        self::assertNull(QueryPlanCapture::decodePlan('not json at all'));
        self::assertNull(QueryPlanCapture::decodePlan('"a bare string"'));
    }

    public function testTheAnalyzeDialectFollowsTheServerVersion(): void
    {
        self::assertSame(AnalyzeDialect::MariaDb, AnalyzeDialect::fromServerVersion('11.4.13-MariaDB-ubu2404'));
        self::assertSame(AnalyzeDialect::MySql, AnalyzeDialect::fromServerVersion('8.0.46'));
        self::assertSame(AnalyzeDialect::MySql, AnalyzeDialect::fromServerVersion('8.4.13'));

        self::assertSame('EXPLAIN ANALYZE FORMAT=TREE SELECT 1', AnalyzeDialect::MySql->analyze('SELECT 1'));
        self::assertSame('ANALYZE FORMAT=JSON SELECT 1', AnalyzeDialect::MariaDb->analyze('SELECT 1'));
    }

    public function testOutcomesOtherThanCapturedCarryNeitherPlanNorAnalysis(): void
    {
        self::assertSame([
            'capture' => 'unsupported',
            'raw' => null,
            'analysis' => null,
        ], CapturedPlan::unsupported()->toArray());
        self::assertSame([
            'capture' => 'failed',
            'raw' => null,
            'analysis' => null,
        ], CapturedPlan::failed()->toArray());
    }

    /**
     * @return iterable<string, array{PlanCapture, array<mixed>|null, string|null}>
     */
    public static function inconsistentPlans(): iterable
    {
        yield 'captured without a plan' => [
            PlanCapture::Captured,
            null,
            '-> analysis',
        ];
        yield 'captured without an analysis' => [
            PlanCapture::Captured,
            ['query_block' => []],
            null,
        ];
        yield 'failed with a plan' => [
            PlanCapture::Failed,
            ['query_block' => []],
            null,
        ];
        yield 'unsupported with an analysis' => [
            PlanCapture::Unsupported,
            null,
            '-> analysis',
        ];
    }

    /**
     * @param array<mixed>|null $plan
     */
    #[DataProvider('inconsistentPlans')]
    public function testRefusesAPlanInconsistentWithItsOutcome(PlanCapture $capture, ?array $plan, ?string $analysis): void
    {
        $this->expectException(InvalidArgumentException::class);
        new CapturedPlan(capture: $capture, plan: $plan, analysis: $analysis);
    }
}
