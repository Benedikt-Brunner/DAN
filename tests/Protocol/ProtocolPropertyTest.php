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

            // fail() must stay outside the try: AssertionFailedError extends
            // RuntimeException, so inside it the catch would swallow the
            // failure and an accepted payload could never fail the property.
            try {
                Protocol::fromDecodedArray($payload);
            } catch (RuntimeException) {
                // Refused - exactly what the property demands. A plain
                // expectException would end the test after the first
                // iteration and silently skip every other generated case.
                $this->addToAssertionCount(1);

                return;
            }

            self::fail(sprintf('Tier "%s" was accepted.', $unknownTier));
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
            $path = DomainGenerators::asPath($parts[0]);
            $payload = DomainGenerators::corruptedAt(
                payload: $protocol->toArray(),
                path: $path,
                junk: $parts[1],
            );

            // fail() must stay outside the try: AssertionFailedError extends
            // RuntimeException, so inside it the catch would swallow the
            // failure and an accepted payload could never fail the property.
            try {
                Protocol::fromDecodedArray($payload);
            } catch (RuntimeException) {
                // Refused - exactly what the property demands. A plain
                // expectException would end the test after the first
                // iteration and silently skip every other generated case.
                $this->addToAssertionCount(1);

                return;
            }

            self::fail(sprintf('Corrupting "%s" was accepted.', implode('.', $path)));
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
            // fail() sits outside the try for the same reason as everywhere
            // else in this suite - here it would even work inside (an
            // AssertionFailedError is no InvalidArgumentException), but one
            // uniform shape keeps the broken variant from being copied.
            try {
                DatabaseTarget::fromString($malformedSpec);
            } catch (InvalidArgumentException) {
                // Refused - exactly what the property demands. A plain
                // expectException would end the test after the first
                // iteration and silently skip every other generated case.
                $this->addToAssertionCount(1);

                return;
            }

            self::fail(sprintf('Spec "%s" was accepted.', $malformedSpec));
        });
    }
}
