<?php

declare(strict_types=1);

namespace Dan\Probe\Tests\Scenario;

use Dan\Lib\Protocol\Tier;
use Dan\Probe\Scenario\Scenario;
use Dan\Probe\Seeding\Dataset\TierSpec;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The corpus as a system, without a database: every scenario is registered,
 * named uniquely, mapped to a cell of the coverage matrix, describes a DAL
 * behaviour, and states a plausible exact total for every tier.
 */
final class CorpusMatrixTest extends TestCase
{
    public function testEveryCorpusScenarioIsRegisteredExactlyOnce(): void
    {
        $classes = self::corpusClasses();
        $services = (string) file_get_contents(__DIR__ . '/../../src/Resources/config/services.yaml');

        self::assertNotSame([], $classes);
        foreach ($classes as $class) {
            self::assertSame(1, substr_count($services, '    ' . $class . ":\n"), sprintf('%s must be tagged dan.scenario exactly once.', $class));
        }
    }

    public function testScenarioNamesAreUniqueAndEveryOneIsInTheCoverageMatrix(): void
    {
        $matrix = (string) file_get_contents(__DIR__ . '/../../../CORPUS.md');
        $names = [];
        foreach (self::corpus() as $scenario) {
            self::assertNotContains($scenario->name(), $names, 'Scenario names must be unique.');
            $names[] = $scenario->name();
            self::assertStringContainsString('`' . $scenario->name() . '`', $matrix, sprintf('%s is not mapped to a cell of CORPUS.md.', $scenario->name()));
            self::assertNotSame('', trim($scenario->describe()));
        }
    }

    public function testExpectedTotalsAreExactAndBoundedForEveryTier(): void
    {
        foreach (Tier::cases() as $tier) {
            $spec = TierSpec::forTier($tier);
            $tableSizes = [
                'product' => $spec->products + $spec->variants(),
                'category' => $spec->categories,
                'product_review' => $spec->reviews,
                'media' => $spec->media,
                'dan_synthetic_blob' => $spec->syntheticBlobs,
            ];
            foreach (self::corpus() as $scenario) {
                $total = $scenario->expectedTotal($spec);
                self::assertGreaterThanOrEqual(0, $total, $scenario->name());
                self::assertLessThanOrEqual($tableSizes[$scenario->entity()] ?? 0, $total, sprintf('%s at %s cannot exceed its table.', $scenario->name(), $tier->value));
            }
        }
    }

    public function testTheCardinalityDimensionIsActuallyCoveredAtTierS(): void
    {
        $spec = TierSpec::forTier(Tier::S);
        $totals = [];
        foreach (self::corpus() as $scenario) {
            $totals[$scenario->name()] = $scenario->expectedTotal($spec);
        }

        self::assertSame(0, $totals['product.empty-result']);
        self::assertSame(2, $totals['product.below-page']);
        self::assertSame(24, $totals['product.page-boundary'], 'Exactly one page of 24.');
        self::assertSame(500, $totals['product.multi-page']);
        self::assertSame(100, $totals['product.inherited-name'], 'One variant per ten products.');
        self::assertSame(1, $totals['product.unindexed-ean']);
        self::assertSame(1_100, $totals['product.keyword-listing'], 'All parents and all variants at S read "DAN Product 000..." with inheritance on.');
        self::assertSame(1_000, $totals['product.score-query'], 'Without inheritance the nameless variants never match.');
        self::assertSame(40, $totals['product.in-category'], '20 parents in category 0 plus their 20 variants.');
        self::assertSame(50, $totals['product.deep-read'], 'No exact count: the total is the page.');
    }

    /**
     * @return list<Scenario>
     */
    private static function corpus(): array
    {
        $scenarios = [];
        foreach (self::corpusClasses() as $class) {
            $scenario = new $class();
            if (!$scenario instanceof Scenario) {
                throw new RuntimeException(sprintf('%s is not a scenario.', $class));
            }
            $scenarios[] = $scenario;
        }

        return $scenarios;
    }

    /**
     * @return list<class-string>
     */
    private static function corpusClasses(): array
    {
        $classes = [];
        foreach (glob(__DIR__ . '/../../src/Scenario/Corpus/*.php') ?: [] as $file) {
            /** @var class-string $class */
            $class = 'Dan\\Probe\\Scenario\\Corpus\\' . basename($file, '.php');
            $classes[] = $class;
        }
        sort($classes);

        return $classes;
    }
}
