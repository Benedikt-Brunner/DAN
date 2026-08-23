<?php

declare(strict_types=1);

namespace Dan\Harness\Tests\Protocol;

use Dan\Harness\Protocol\Engine;
use Dan\Harness\Protocol\Protocol;
use Dan\Harness\Protocol\ProtocolResolver;
use Dan\Lib\Protocol\Tier;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ProtocolResolverTest extends TestCase
{
    public function testResolvesDatabaseSpecsAndTiers(): void
    {
        $resolver = new ProtocolResolver();

        $protocol = $resolver->resolve(
            databaseSpecs: [
                'mysql:8.0',
                'mariadb:11.4',
            ],
            tiers: [
                'S',
                'M',
            ],
            warmupIterations: 5,
            measuredIterations: 30,
            blocks: 4,
            scenarioFilter: null,
        );

        self::assertCount(2, $protocol->databases);
        self::assertSame(Engine::MySql, $protocol->databases[0]->engine);
        self::assertSame('8.0', $protocol->databases[0]->version);
        self::assertSame(Engine::MariaDb, $protocol->databases[1]->engine);
        self::assertSame([
            Tier::S,
            Tier::M,
        ], $protocol->tiers);
    }

    public function testRoundTripsThroughArraySerialization(): void
    {
        $resolver = new ProtocolResolver();
        $protocol = $resolver->resolve(
            databaseSpecs: ['mysql:8.4'],
            tiers: ['L'],
            warmupIterations: 3,
            measuredIterations: 10,
            blocks: 2,
            scenarioFilter: 'product.',
        );

        $restored = Protocol::fromArray($protocol->toArray());

        self::assertTrue($protocol->equals($restored));
    }

    public function testRejectsUnknownTier(): void
    {
        $resolver = new ProtocolResolver();

        $this->expectException(InvalidArgumentException::class);
        $resolver->resolve(
            databaseSpecs: ['mysql:8.0'],
            tiers: ['XL'],
            warmupIterations: 5,
            measuredIterations: 30,
            blocks: 4,
            scenarioFilter: null,
        );
    }

    public function testRejectsUnknownEngine(): void
    {
        $resolver = new ProtocolResolver();

        $this->expectException(InvalidArgumentException::class);
        $resolver->resolve(
            databaseSpecs: ['postgres:16'],
            tiers: ['S'],
            warmupIterations: 5,
            measuredIterations: 30,
            blocks: 4,
            scenarioFilter: null,
        );
    }

    public function testRejectsMoreBlocksThanIterations(): void
    {
        $resolver = new ProtocolResolver();

        $this->expectException(InvalidArgumentException::class);
        $resolver->resolve(
            databaseSpecs: ['mysql:8.0'],
            tiers: ['S'],
            warmupIterations: 5,
            measuredIterations: 3,
            blocks: 4,
            scenarioFilter: null,
        );
    }

    public function testRejectsEmptyDatabaseList(): void
    {
        $resolver = new ProtocolResolver();

        $this->expectException(InvalidArgumentException::class);
        $resolver->resolve(
            databaseSpecs: [],
            tiers: ['S'],
            warmupIterations: 5,
            measuredIterations: 30,
            blocks: 4,
            scenarioFilter: null,
        );
    }
}
