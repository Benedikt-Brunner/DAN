<?php

declare(strict_types=1);

namespace Dan\Harness\Tests\Comparison;

use Dan\Harness\Comparison\BlockComparison;
use Dan\Harness\Comparison\BlockComparisonCollection;
use Dan\Harness\Measurement\Result\SampleCollection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BlockComparisonCollectionTest extends TestCase
{
    /**
     * @param list<array{int, int}> $medianMsPairs baseline and candidate median per block
     */
    #[DataProvider('blockEffects')]
    public function testEffectsDisagreeOnlyWhenSomeBlocksSawTheCandidateFasterAndOthersSlower(array $medianMsPairs, bool $disagree): void
    {
        $blocks = [];
        foreach (
            $medianMsPairs as $blockIndex => [
                $baselineMs,
                $candidateMs,
            ]
        ) {
            $blocks[] = new BlockComparison(
                blockIndex: $blockIndex,
                baselineExecutionOrder: 2 * $blockIndex,
                candidateExecutionOrder: 2 * $blockIndex + 1,
                baselineSamples: SampleCollection::fromArray([$baselineMs * 1_000_000]),
                candidateSamples: SampleCollection::fromArray([$candidateMs * 1_000_000]),
            );
        }

        self::assertSame($disagree, BlockComparisonCollection::create($blocks)->effectsDisagree());
    }

    /**
     * @return iterable<string, array{list<array{int, int}>, bool}>
     */
    public static function blockEffects(): iterable
    {
        yield 'no blocks' => [
            [],
            false,
        ];
        yield 'every block faster' => [
            [
                [
                    10,
                    8,
                ],
                [
                    10,
                    9,
                ],
            ],
            false,
        ];
        yield 'unchanged and slower' => [
            [
                [
                    10,
                    10,
                ],
                [
                    10,
                    12,
                ],
            ],
            false,
        ];
        yield 'faster and slower' => [
            [
                [
                    10,
                    8,
                ],
                [
                    10,
                    10,
                ],
                [
                    10,
                    12,
                ],
            ],
            true,
        ];
    }
}
