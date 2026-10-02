<?php

declare(strict_types=1);

namespace Dan\Harness\Tests\Lib\Protocol;

use Dan\Lib\Protocol\StatementDivergence;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StatementDivergenceTest extends TestCase
{
    public function testFlagsMapOntoTheFourKinds(): void
    {
        self::assertSame(StatementDivergence::None, StatementDivergence::fromFlags(textDiffers: false, intermittent: false));
        self::assertSame(StatementDivergence::Text, StatementDivergence::fromFlags(textDiffers: true, intermittent: false));
        self::assertSame(StatementDivergence::Presence, StatementDivergence::fromFlags(textDiffers: false, intermittent: true));
        self::assertSame(StatementDivergence::TextAndPresence, StatementDivergence::fromFlags(textDiffers: true, intermittent: true));
    }

    public function testOnlyNoneIsStable(): void
    {
        foreach (StatementDivergence::cases() as $kind) {
            self::assertSame($kind !== StatementDivergence::None, $kind->isDivergent());
        }
    }

    #[DataProvider('kinds')]
    public function testMergeIsTheUnionOfBothKindsFlags(StatementDivergence $left, StatementDivergence $right): void
    {
        $merged = $left->merge($right);

        self::assertSame($left->includesText() || $right->includesText(), $merged->includesText());
        self::assertSame($left->includesPresence() || $right->includesPresence(), $merged->includesPresence());
        self::assertSame($merged, $right->merge($left), 'Merging is commutative.');
        self::assertSame($merged, $merged->merge($left), 'Merging is idempotent.');
    }

    /**
     * @return iterable<string, array{StatementDivergence, StatementDivergence}>
     */
    public static function kinds(): iterable
    {
        foreach (StatementDivergence::cases() as $left) {
            foreach (StatementDivergence::cases() as $right) {
                yield $left->value . ' + ' . $right->value => [
                    $left,
                    $right,
                ];
            }
        }
    }
}
