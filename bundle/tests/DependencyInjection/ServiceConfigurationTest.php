<?php

declare(strict_types=1);

namespace Dan\Probe\Tests\DependencyInjection;

use Dan\Probe\DanProbeBundle;
use Dan\Probe\Execution\Command\ExecuteScenariosCommand;
use Dan\Probe\Execution\Measurement\ScenarioMeasurer;
use Dan\Probe\Execution\Result\ScenarioResultWriter;
use Dan\Probe\Recorder\QueryRecorder;
use Dan\Probe\Recorder\RecordingBootstrap;
use Dan\Probe\Scenario\Corpus\ProductDeepReadScenario;
use Dan\Probe\Scenario\Corpus\ProductKeywordListingScenario;
use Dan\Probe\Scenario\Corpus\SyntheticJsonPathScenario;
use Dan\Probe\Scenario\ScenarioRegistry;
use Dan\Probe\Seeding\Command\SeedTierCommand;
use Dan\Probe\Seeding\Dataset\DatasetSeeder;
use Dan\Probe\Synthetic\SyntheticBlobDefinition;
use Dan\Probe\Synthetic\SyntheticSchemaInstaller;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class ServiceConfigurationTest extends TestCase
{
    public function testLoadsAutowiredAndAutoconfiguredServiceDefinitions(): void
    {
        $container = new ContainerBuilder();
        (new DanProbeBundle())->build($container);

        $serviceIds = [
            ScenarioRegistry::class,
            ProductKeywordListingScenario::class,
            ProductDeepReadScenario::class,
            SyntheticJsonPathScenario::class,
            DatasetSeeder::class,
            QueryRecorder::class,
            ScenarioMeasurer::class,
            ScenarioResultWriter::class,
            SyntheticSchemaInstaller::class,
            ExecuteScenariosCommand::class,
            SeedTierCommand::class,
        ];

        foreach ($serviceIds as $serviceId) {
            $definition = $container->getDefinition($serviceId);

            self::assertTrue($definition->isAutowired(), $serviceId);
            self::assertTrue($definition->isAutoconfigured(), $serviceId);
        }

        $syntheticDefinition = $container->getDefinition(SyntheticBlobDefinition::class);
        self::assertTrue($syntheticDefinition->isAutowired());
        self::assertTrue($syntheticDefinition->isAutoconfigured());

        self::assertInstanceOf(
            TaggedIteratorArgument::class,
            $container->getDefinition(ScenarioRegistry::class)->getArgument('$scenarios'),
        );
        // The container's recorder must be the instance the recording
        // middleware writes into - RecordingBootstrap hands it across the
        // pre-container boundary.
        self::assertSame([
            RecordingBootstrap::class,
            'recorder',
        ], $container->getDefinition(QueryRecorder::class)->getFactory());

        self::assertTrue($container->getDefinition(ProductKeywordListingScenario::class)->hasTag('dan.scenario'));
        self::assertTrue($container->getDefinition(ProductDeepReadScenario::class)->hasTag('dan.scenario'));
        self::assertTrue($container->getDefinition(SyntheticJsonPathScenario::class)->hasTag('dan.scenario'));
        self::assertSame([], $syntheticDefinition->getTag('shopware.entity.definition'));
    }
}
