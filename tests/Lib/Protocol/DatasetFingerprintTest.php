<?php

declare(strict_types=1);

namespace Dan\Harness\Tests\Lib\Protocol;

use Dan\Lib\Protocol\DatasetAspect;
use Dan\Lib\Protocol\DatasetFingerprint;
use Dan\Lib\Protocol\DatasetFingerprintSchemaVersion;
use Dan\Lib\Protocol\Tier;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DatasetFingerprintTest extends TestCase
{
    public function testEqualFingerprintsHaveNoDifferences(): void
    {
        $fingerprint = self::fingerprint(['product' => [
            1000,
            '12-34',
        ]]);

        self::assertTrue($fingerprint->equals(self::fingerprint(['product' => [
            1000,
            '12-34',
        ]])));
        self::assertSame([], $fingerprint->differences(self::fingerprint(['product' => [
            1000,
            '12-34',
        ]])));
    }

    public function testDifferencesNameTheAspectAndWhatChanged(): void
    {
        $seeded = self::fingerprint([
            'product' => [
                1000,
                '1-1',
            ],
            'product.categories' => [
                1000,
                '2-2',
            ],
            'tax' => [
                1,
                '3-3',
            ],
        ]);
        $other = self::fingerprint([
            'product' => [
                1000,
                '9-9',
            ],
            'product.categories' => [
                999,
                '2-2',
            ],
            'synthetic-blob' => [
                1000,
                '4-4',
            ],
        ]);

        self::assertFalse($seeded->equals($other));
        self::assertSame([
            'product: same 1000 rows, different values',
            'product.categories: 1000 vs 999 rows',
            'tax: missing on the other side',
            'synthetic-blob: only on the other side',
        ], $seeded->differences($other));
    }

    public function testADifferentTierIsADifference(): void
    {
        $small = self::fingerprint(['product' => [
            1000,
            '1-1',
        ]]);
        $medium = new DatasetFingerprint(tier: Tier::M, aspects: [new DatasetAspect(name: 'product', rows: 1000, checksum: '1-1')]);

        self::assertSame(['tier S vs M'], $small->differences($medium));
    }

    public function testRoundTripsThroughItsPayload(): void
    {
        $fingerprint = self::fingerprint([
            'product' => [
                1000,
                '1-1',
            ],
            'tax' => [
                1,
                '3-3',
            ],
        ]);

        $payload = $fingerprint->toArray();

        self::assertSame(DatasetFingerprintSchemaVersion::getCurrent()->value, $payload['schemaVersion']);
        self::assertSame($payload, DatasetFingerprint::fromDecodedArray($payload)->toArray());
    }

    public function testRefusesAForeignSchemaVersion(): void
    {
        $payload = self::fingerprint(['product' => [
            1,
            '1-1',
        ]])->toArray();
        $payload['schemaVersion'] = 99;

        $this->expectException(RuntimeException::class);
        DatasetFingerprint::fromDecodedArray($payload);
    }

    public function testRefusesMalformedAspects(): void
    {
        $this->expectException(RuntimeException::class);
        DatasetFingerprint::fromDecodedArray([
            'schemaVersion' => 1,
            'tier' => 'S',
            'aspects' => [[
                'name' => 'product',
                'rows' => '1000',
                'checksum' => '1-1',
            ]],
        ]);
    }

    /**
     * @param array<string, array{int, string}> $aspects name => rows, checksum
     */
    private static function fingerprint(array $aspects): DatasetFingerprint
    {
        $built = [];
        foreach (
            $aspects as $name => [
                $rows,
                $checksum,
            ]
        ) {
            $built[] = new DatasetAspect(name: $name, rows: $rows, checksum: $checksum);
        }

        return new DatasetFingerprint(tier: Tier::S, aspects: $built);
    }
}
