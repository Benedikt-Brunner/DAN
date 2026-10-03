<?php

declare(strict_types=1);

namespace Dan\Harness\Tests\RunStore\Artifact;

use Dan\Harness\Measurement\Scheduling\MeasurementBlock;
use Dan\Harness\Measurement\Scheduling\RunSlot;
use Dan\Harness\Protocol\DatabaseTarget;
use Dan\Harness\Protocol\Engine;
use Dan\Harness\RunStore\Artifact\CellResult;
use Dan\Lib\Protocol\ScenarioResultSchemaVersion;
use Dan\Lib\Protocol\Tier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Adopting a dan:execute result as a scheduled block: the probe reports what
 * it ran, and anything that disagrees with the schedule is refused so a
 * block can never be recorded under the wrong identity or with the wrong
 * number of samples.
 */
final class CellResultTest extends TestCase
{
    public function testAdoptsTheScheduledBlockIdentity(): void
    {
        $cell = CellResult::fromDecodedScenarioArray(
            payload: self::payload(),
            tier: Tier::M,
            database: self::database(),
            block: self::block(),
        );

        self::assertSame(Tier::M, $cell->tier);
        self::assertSame('product.deep-read', $cell->scenario->toString());
        self::assertCount(1, $cell->blocks);
        self::assertSame(1, $cell->blocks[0]->blockIndex);
        self::assertSame(3, $cell->blocks[0]->executionOrder);
        self::assertSame(2, $cell->blocks[0]->warmupIterations);
        self::assertSame(2, $cell->blocks[0]->iterations());
    }

    public function testRefusesAResultWhoseMeasuredIterationsDisagreeWithTheSchedule(): void
    {
        self::assertRefusedWith(
            message: 'measured 2 iterations but block 1 was scheduled with 5',
            payload: self::payload(),
            block: self::block(iterations: 5),
        );
    }

    public function testRefusesAResultWhoseWarmupDisagreesWithTheSchedule(): void
    {
        self::assertRefusedWith(
            message: 'ran 2 warmup iterations but block 1 was scheduled with 0',
            payload: self::payload(),
            block: self::block(warmup: 0),
        );
    }

    public function testRefusesAResultWithFewerWallSamplesThanIterations(): void
    {
        $payload = self::payload();
        $payload['wallNsSamples'] = [1_000_000];

        self::assertRefusedWith(
            message: '1 wall samples for 2 measured iterations',
            payload: $payload,
            block: self::block(),
        );
    }

    public function testRefusesAForeignScenarioResultSchemaVersion(): void
    {
        $payload = self::payload();
        $payload['schemaVersion'] = ScenarioResultSchemaVersion::getCurrent()->value + 1;

        self::assertRefusedWith(
            message: 'Unsupported scenario result schema version',
            payload: $payload,
            block: self::block(),
        );
    }

    /**
     * @param list<int|string> $path
     */
    #[DataProvider('corruptions')]
    public function testRefusesTypeCorruptedScenarioPayloads(array $path, mixed $junk): void
    {
        $payload = self::payload();
        $target = &$payload;
        foreach (array_slice($path, 0, -1) as $segment) {
            if (!is_array($target[$segment])) {
                self::fail('Corruption path does not address a nested payload.');
            }
            $target = &$target[$segment];
        }
        $target[$path[count($path) - 1]] = $junk;
        unset($target);

        $this->expectException(RuntimeException::class);

        CellResult::fromDecodedScenarioArray(
            payload: $payload,
            tier: Tier::S,
            database: self::database(),
            block: self::block(),
        );
    }

    /**
     * @return iterable<string, array{list<int|string>, mixed}>
     */
    public static function corruptions(): iterable
    {
        yield 'schema version' => [
            ['schemaVersion'],
            '1',
        ];
        yield 'scenario' => [
            ['scenario'],
            7,
        ];
        yield 'warmup' => [
            ['warmupIterations'],
            '2',
        ];
        yield 'measured iterations' => [
            ['measuredIterations'],
            2.0,
        ];
        yield 'wall samples' => [
            ['wallNsSamples'],
            'none',
        ];
        yield 'wall sample' => [
            [
                'wallNsSamples',
                0,
            ],
            'fast',
        ];
        yield 'statements' => [
            ['statements'],
            'none',
        ];
        yield 'statement' => [
            [
                'statements',
                0,
            ],
            'statement',
        ];
    }

    /**
     * @param array<mixed> $payload
     */
    private static function assertRefusedWith(string $message, array $payload, MeasurementBlock $block): void
    {
        try {
            CellResult::fromDecodedScenarioArray(
                payload: $payload,
                tier: Tier::S,
                database: self::database(),
                block: $block,
            );
        } catch (RuntimeException $refusal) {
            self::assertStringContainsString($message, $refusal->getMessage());

            return;
        }

        self::fail('The scenario result was accepted although it disagrees with the schedule.');
    }

    /**
     * @return array<string, mixed>
     */
    private static function payload(): array
    {
        return [
            'schemaVersion' => ScenarioResultSchemaVersion::getCurrent()->value,
            'scenario' => 'product.deep-read',
            'entity' => 'product',
            'dalVersion' => 'v6.6.10.22',
            'warmupIterations' => 2,
            'measuredIterations' => 2,
            'wallNsSamples' => [
                1_000_000,
                1_100_000,
            ],
            'statements' => [
                [
                    'index' => 0,
                    'sql' => 'SELECT 1',
                    'durationsNsSamples' => [
                        400_000,
                        410_000,
                    ],
                    'divergent' => false,
                ],
            ],
        ];
    }

    private static function block(int $warmup = 2, int $iterations = 2): MeasurementBlock
    {
        return new MeasurementBlock(slot: RunSlot::Baseline, warmupIterations: $warmup, iterations: $iterations, blockIndex: 1, executionOrder: 3);
    }

    private static function database(): DatabaseTarget
    {
        return new DatabaseTarget(engine: Engine::MySql, version: '8.0');
    }
}
