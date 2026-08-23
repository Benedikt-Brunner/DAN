<?php

declare(strict_types=1);

namespace Dan\Probe\Tests\Scenario;

use Dan\Probe\Scenario\Corpus\ProductDeepReadScenario;
use Dan\Probe\Scenario\Corpus\ProductKeywordListingScenario;
use Dan\Probe\Scenario\Corpus\SyntheticJsonPathScenario;
use Dan\Probe\Scenario\ScenarioRegistry;
use PHPUnit\Framework\TestCase;

final class ScenarioRegistryTest extends TestCase
{
    public function testFiltersScenariosByName(): void
    {
        $deepRead = new ProductDeepReadScenario();
        $keywordListing = new ProductKeywordListingScenario();
        $syntheticJsonPath = new SyntheticJsonPathScenario();
        $registry = new ScenarioRegistry([
            $deepRead,
            $keywordListing,
            $syntheticJsonPath,
        ]);

        self::assertSame(
            [
                $deepRead,
                $keywordListing,
                $syntheticJsonPath,
            ],
            $registry->matching(filter: null),
        );
        self::assertSame(
            [$keywordListing],
            $registry->matching(filter: 'keyword'),
        );
        self::assertSame(
            [$syntheticJsonPath],
            $registry->matching(filter: 'synthetic'),
        );
    }
}
