<?php

declare(strict_types=1);

namespace Dan\Harness\Tests\Protocol;

use Dan\Harness\Protocol\DatabaseTarget;
use Dan\Harness\Protocol\Protocol;
use Dan\Harness\Tests\DomainGenerators;
use Dan\Harness\Tests\PropertyTestCase;
use Eris\Generator;
use InvalidArgumentException;
use RuntimeException;

/**
 * Round-trip fidelity of the protocol payloads. The protocol frozen into a
 * run manifest is what makes profiles comparable at all - any lossy
 * serialization would let mismatched runs diff silently.
 */
final class ProtocolPropertyTest extends PropertyTestCase
{
    public function testProtocolSurvivesItsOwnPayload(): void
    {
        $this->forAll(DomainGenerators::protocol())->then(function (Protocol $protocol): void {
            $rebuilt = Protocol::fromArray($protocol->toArray());

            self::assertTrue($rebuilt->equals($protocol));
            self::assertSame($protocol->toArray(), $rebuilt->toArray());
        });
    }

    public function testProtocolSurvivesJsonTransport(): void
    {
        $this->forAll(DomainGenerators::protocol())->then(function (Protocol $protocol): void {
            $decoded = json_decode(json_encode($protocol->toArray(), \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR);
            self::assertIsArray($decoded);

            self::assertTrue(Protocol::fromDecodedArray($decoded)->equals($protocol));
        });
    }

    public function testProtocolRefusesPayloadsWithUnknownTiers(): void
    {
        $this->forAll(
            DomainGenerators::protocol(),
            Generator\elements('XL', 'small', 's', '', 'tier-1'),
        )->then(function (Protocol $protocol, string $unknownTier): void {
            $payload = $protocol->toArray();
            $payload['tiers'][] = $unknownTier;

            try {
                Protocol::fromDecodedArray($payload);
                self::fail('The malformed input was accepted.');
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
                // Refused - exactly what the property demands. A plain
                // expectException would end the test after the first
                // iteration and silently skip every other generated case.
            }
        });
    }

    public function testRefusesTypeCorruptedPayloads(): void
    {
        $corruptions = [
            [
                ['databases'],
                'none',
            ],
            [
                [
                    'databases',
                    0,
                ],
                'mysql-8.0',
            ],
            [
                ['tiers'],
                3.14,
            ],
            [
                [
                    'tiers',
                    0,
                ],
                99,
            ],
            [
                ['warmupIterations'],
                'five',
            ],
            [
                ['measuredIterations'],
                null,
            ],
            [
                ['blocks'],
                [],
            ],
            [
                ['scenarioFilter'],
                123,
            ],
        ];

        $this->forAll(
            DomainGenerators::protocol(),
            Generator\elements(...$corruptions),
        )->then(function (Protocol $protocol, mixed $corruption): void {
            $parts = DomainGenerators::asList($corruption);
            $payload = DomainGenerators::corruptedAt(
                payload: $protocol->toArray(),
                path: DomainGenerators::asPath($parts[0]),
                junk: $parts[1],
            );

            try {
                Protocol::fromDecodedArray($payload);
                self::fail('The malformed input was accepted.');
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
                // Refused - exactly what the property demands. A plain
                // expectException would end the test after the first
                // iteration and silently skip every other generated case.
            }
        });
    }

    public function testDatabaseTargetIdIsTheEngineDashVersionFileNameKey(): void
    {
        // id() is persisted inside cell file names - the join key between
        // two runs - so its exact shape is a contract, not a convenience.
        $this->forAll(DomainGenerators::databaseTarget())->then(function (DatabaseTarget $target): void {
            self::assertSame($target->engine->value . '-' . $target->version, $target->id());
        });
    }

    public function testDatabaseTargetSurvivesJsonTransportAndSpecParsing(): void
    {
        $this->forAll(DomainGenerators::databaseTarget())->then(function (DatabaseTarget $target): void {
            $decoded = json_decode(json_encode($target->toArray(), \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR);
            self::assertIsArray($decoded);
            $rebuilt = DatabaseTarget::fromDecodedArray($decoded);

            self::assertSame($target->toArray(), $rebuilt->toArray());
            self::assertSame($target->id(), $rebuilt->id());
            // The CLI spec "<engine>:<version>" must reproduce the same
            // target - including versions that themselves contain colons.
            $parsed = DatabaseTarget::fromString($target->engine->value . ':' . $target->version);
            self::assertSame($target->toArray(), $parsed->toArray());
        });
    }

    public function testDatabaseTargetRefusesSpecsWithoutEngineAndVersion(): void
    {
        $this->forAll(Generator\elements(
            'mysql',
            'mysql:',
            'postgres:16',
            ':8.0',
            '',
            'sqlite',
        ))->then(function (string $malformedSpec): void {
            try {
                DatabaseTarget::fromString($malformedSpec);
                self::fail('The malformed input was accepted.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
                // Refused - exactly what the property demands. A plain
                // expectException would end the test after the first
                // iteration and silently skip every other generated case.
            }
        });
    }
}
