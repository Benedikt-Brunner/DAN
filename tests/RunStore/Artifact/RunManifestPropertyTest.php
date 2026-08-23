<?php

declare(strict_types=1);

namespace Dan\Harness\Tests\RunStore\Artifact;

use Dan\Harness\RunStore\Artifact\RunManifest;
use Dan\Harness\Tests\DomainGenerators;
use Dan\Harness\Tests\PropertyTestCase;
use Eris\Generator;
use RuntimeException;

/**
 * Run manifests are the self-description of every profile; a lossy round
 * trip would detach recorded numbers from the protocol and implementation
 * that produced them.
 */
final class RunManifestPropertyTest extends PropertyTestCase
{
    public function testManifestSurvivesJsonTransport(): void
    {
        $this->forAll(DomainGenerators::runManifest())->then(function (RunManifest $manifest): void {
            $decoded = json_decode(json_encode($manifest->toArray(), \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR);
            self::assertIsArray($decoded);

            self::assertSame($manifest->toArray(), RunManifest::fromDecodedArray($decoded)->toArray());
        });
    }

    public function testRefusesEveryForeignSchemaVersion(): void
    {
        $this->forAll(
            DomainGenerators::runManifest(),
            Generator\suchThat(
                fn (int $version): bool => $version !== RunManifest::SCHEMA_VERSION,
                Generator\choose(-1000, 1000),
            ),
        )->then(function (RunManifest $manifest, int $foreignVersion): void {
            $payload = $manifest->toArray();
            $payload['schemaVersion'] = $foreignVersion;

            try {
                RunManifest::fromDecodedArray($payload);
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
                ['schemaVersion'],
                'one',
            ],
            [
                ['runId'],
                10,
            ],
            [
                ['createdAt'],
                999,
            ],
            [
                ['implementation'],
                'impl',
            ],
            [
                [
                    'implementation',
                    'reference',
                ],
                'ref',
            ],
            [
                [
                    'implementation',
                    'reference',
                    'type',
                ],
                4,
            ],
            [
                [
                    'implementation',
                    'reference',
                    'value',
                ],
                4,
            ],
            [
                [
                    'implementation',
                    'identity',
                ],
                'id',
            ],
            [
                [
                    'implementation',
                    'identity',
                    'id',
                ],
                1,
            ],
            [
                [
                    'implementation',
                    'identity',
                    'label',
                ],
                2,
            ],
            [
                ['protocol'],
                'protocol',
            ],
            [
                [
                    'protocol',
                    'measuredIterations',
                ],
                'many',
            ],
        ];

        $this->forAll(
            DomainGenerators::runManifest(),
            Generator\elements(...$corruptions),
        )->then(function (RunManifest $manifest, mixed $corruption): void {
            $parts = DomainGenerators::asList($corruption);
            $payload = DomainGenerators::corruptedAt(
                payload: $manifest->toArray(),
                path: DomainGenerators::asPath($parts[0]),
                junk: $parts[1],
            );

            try {
                RunManifest::fromDecodedArray($payload);
                self::fail('The malformed input was accepted.');
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
                // Refused - exactly what the property demands. A plain
                // expectException would end the test after the first
                // iteration and silently skip every other generated case.
            }
        });
    }

    public function testRefusesUnknownReferenceTypes(): void
    {
        $this->forAll(
            DomainGenerators::runManifest(),
            Generator\elements('tag', 'branch', 'RELEASE', '', 'zip'),
        )->then(function (RunManifest $manifest, string $unknownType): void {
            $payload = $manifest->toArray();
            $payload['implementation']['reference']['type'] = $unknownType;

            try {
                RunManifest::fromDecodedArray($payload);
                self::fail('The malformed input was accepted.');
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
                // Refused - exactly what the property demands. A plain
                // expectException would end the test after the first
                // iteration and silently skip every other generated case.
            }
        });
    }
}
