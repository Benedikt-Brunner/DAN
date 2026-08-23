<?php

declare(strict_types=1);

namespace Dan\Harness\Tests\Measure;

use Dan\Harness\Measurement\Result\SampleCollection;
use PHPUnit\Framework\TestCase;

final class SampleCollectionTest extends TestCase
{
    public function testCreatesSamplesFromNumericArray(): void
    {
        $samples = SampleCollection::fromArray([
            10,
            2.5,
        ]);

        self::assertSame(10.0, $samples[0]->duration()->toNsFloat());
        self::assertSame(2.5, $samples[1]->duration()->toNsFloat());
    }

    public function testNormalizesInputKeys(): void
    {
        $samples = SampleCollection::fromArray([
            5 => 10,
            9 => 20,
        ]);

        self::assertSame(10.0, $samples[0]->duration()->toNsFloat());
        self::assertSame(20.0, $samples[1]->duration()->toNsFloat());
    }
}
