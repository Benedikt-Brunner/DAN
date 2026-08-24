<?php

declare(strict_types=1);

namespace Dan\Harness\Tests\Comparison;

use Dan\Harness\Comparison\SqlNormalizer;
use Dan\Harness\Tests\DomainGenerators;
use Dan\Harness\Tests\PropertyTestCase;
use Dan\Lib\Time\Duration;
use Dan\Lib\Time\Timestamp;
use Eris\Generator;

/**
 * Properties of SqlNormalizer over generated SQL. Diffing rests on these:
 * normalization must be a projection (idempotent), must erase exactly the
 * incidental differences (whitespace runs, IN-list arity), and must stay
 * cheap and lossless on adversarial input (the fuzz-lite ReDoS guard - the
 * normalizer runs over every recorded statement of every run).
 */
final class SqlNormalizerPropertyTest extends PropertyTestCase
{
    private const array WHITESPACE = [
        ' ',
        '  ',
        "\t",
        "\n",
        " \n\t ",
    ];

    private const array TOKENS = [
        'SELECT',
        'DISTINCT',
        '`product`.`id`',
        'category.name,',
        'FROM',
        'product',
        'LEFT',
        'JOIN',
        'category',
        'ON',
        'category.id',
        '=',
        'product.category_id',
        'WHERE',
        'product.id',
        'IN',
        'AND',
        'category.active',
        'ORDER',
        'BY',
        'product.name',
    ];

    public function testNormalizationIsIdempotentOnAnyString(): void
    {
        $this->forAll(Generator\string())->then(function (string $sql): void {
            $once = SqlNormalizer::normalize($sql);

            self::assertSame($once, SqlNormalizer::normalize($once));
        });
    }

    public function testNormalizationIsIdempotentOnGeneratedSql(): void
    {
        $this->forAll($this->sqlStatements())->then(function (string $sql): void {
            $once = SqlNormalizer::normalize($sql);

            self::assertSame($once, SqlNormalizer::normalize($once));
        });
    }

    public function testNormalizedFormIsIndependentOfInListArityAndWhitespace(): void
    {
        // Two statements that differ only in whitespace runs and in how many
        // placeholders their IN lists carry (two or more) must normalize to
        // the identical string - that is the false-diff class the normalizer
        // exists to erase.
        $this->forAll(
            $this->tokenSequences(),
            Generator\choose(2, 40),
            Generator\choose(2, 40),
            $this->whitespace(),
            $this->whitespace(),
            Generator\elements('?', ':p'),
        )->then(function (mixed $tokens, int $leftArity, int $rightArity, string $leftSpace, string $rightSpace, string $placeholder): void {
            $tokens = DomainGenerators::asStringList($tokens);
            $left = self::statement(tokens: $tokens, arity: $leftArity, whitespace: $leftSpace, placeholder: $placeholder);
            $right = self::statement(tokens: $tokens, arity: $rightArity, whitespace: $rightSpace, placeholder: $placeholder);

            self::assertSame(SqlNormalizer::normalize($left), SqlNormalizer::normalize($right));
        });
    }

    public function testSinglePlaceholderGroupsAreNeverCollapsed(): void
    {
        // A single-value IN (or any one-placeholder parenthesis) is a stable
        // query shape: only the *variable-arity* groups (two or more) may
        // collapse, otherwise structurally different statements would merge.
        $this->forAll(
            $this->tokenSequences(),
            $this->whitespace(),
            Generator\elements('?', ':p'),
        )->then(function (mixed $tokens, string $whitespace, string $placeholder): void {
            $tokens = DomainGenerators::asStringList($tokens);
            $single = self::statement(tokens: $tokens, arity: 1, whitespace: $whitespace, placeholder: $placeholder);
            $collapsed = self::statement(tokens: $tokens, arity: 2, whitespace: $whitespace, placeholder: $placeholder);

            self::assertNotSame(SqlNormalizer::normalize($single), SqlNormalizer::normalize($collapsed));
        });
    }

    public function testNormalizationOfAdversarialSqlStaysWithinTimeBudgetAndLosesNothing(): void
    {
        // Fuzz-lite ReDoS guard: pathological placeholder runs, whitespace
        // floods and paren nesting must neither blow up the regex engine
        // (catastrophic backtracking) nor silently return an empty string
        // (preg_replace() returning null on a backtrack-limit breach would be
        // cast to '' - a normalizer bug class that erases recorded SQL).
        $budget = Duration::fromSeconds(0.25);

        $this->forAll($this->adversarialSql())->then(function (string $sql) use ($budget): void {
            $start = Timestamp::now();
            $normalized = SqlNormalizer::normalize($sql);
            $elapsed = $start->elapsed();

            self::assertTrue($budget->isAtLeast($elapsed), sprintf(
                'Normalizing %d chars took %.1fms - catastrophic backtracking?',
                strlen($sql),
                $elapsed->toMsFloat(),
            ));
            self::assertNotSame('', $normalized);
        });
    }

    /**
     * A whole statement: tokens joined by random whitespace, with one
     * placeholder group of the given arity appended.
     *
     * @param list<string> $tokens
     */
    private static function statement(array $tokens, int $arity, string $whitespace, string $placeholder): string
    {
        return implode($whitespace, $tokens) . $whitespace
            . '(' . implode(',' . $whitespace, self::placeholders(arity: $arity, placeholder: $placeholder)) . ')';
    }

    /**
     * @return list<string>
     */
    private static function placeholders(int $arity, string $placeholder): array
    {
        return array_map(
            fn (int $index): string => $placeholder === '?' ? '?' : ':p' . $index,
            range(1, $arity),
        );
    }

    /** @return Generator<mixed> */
    private function sqlStatements(): Generator
    {
        return Generator\map(
            self::buildStatement(...),
            Generator\tuple(
                $this->tokenSequences(),
                Generator\choose(1, 40),
                $this->whitespace(),
                Generator\elements('?', ':p'),
            ),
        );
    }

    /**
     * @param array<mixed> $parts
     */
    private static function buildStatement(array $parts): string
    {
        return self::statement(
            tokens: DomainGenerators::asStringList($parts[0]),
            arity: DomainGenerators::asInt($parts[1]),
            whitespace: DomainGenerators::asString($parts[2]),
            placeholder: DomainGenerators::asString($parts[3]),
        );
    }

    /** @return Generator<mixed> non-empty token sequences */
    private function tokenSequences(): Generator
    {
        return DomainGenerators::boundedList(elements: Generator\elements(...self::TOKENS), maxLength: 15);
    }

    /** @return Generator<mixed> */
    private function whitespace(): Generator
    {
        return Generator\elements(...self::WHITESPACE);
    }

    /** @return Generator<mixed> */
    private function adversarialSql(): Generator
    {
        // The malformed shapes matter most: catastrophic backtracking shows
        // up when a long placeholder run ultimately FAILS to match (an
        // unclosed group forces the engine through every partition of the
        // run), not on the well-formed groups that match on first try.
        return Generator\map(
            self::buildAdversarialStatement(...),
            Generator\tuple(
                Generator\choose(500, 3000),
                Generator\choose(1, 40),
                Generator\choose(0, 30),
                Generator\elements('?', ':p'),
                Generator\elements('closed', 'unclosed', 'trailing-comma'),
            ),
        );
    }

    /**
     * @param array<mixed> $parts
     */
    private static function buildAdversarialStatement(array $parts): string
    {
        $whitespace = str_repeat(' ', DomainGenerators::asInt($parts[1]));
        $nesting = DomainGenerators::asInt($parts[2]);
        $names = self::placeholders(arity: DomainGenerators::asInt($parts[0]), placeholder: DomainGenerators::asString($parts[3]));
        $run = $whitespace . implode($whitespace . ',' . $whitespace, $names) . $whitespace;
        $group = match (DomainGenerators::asString($parts[4])) {
            'closed' => '(' . $run . ')',
            'unclosed' => '(' . $run,
            'trailing-comma' => '(' . $run . ',)',
            default => '(' . $run . ')',
        };

        return 'SELECT id FROM t WHERE x IN '
            . str_repeat('(', $nesting) . $group . str_repeat(')', $nesting)
            . ' AND y IN ' . $group;
    }
}
