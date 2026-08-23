<?php

declare(strict_types=1);

namespace Dan\Harness\Tests\PhpStan;

use Dan\Harness\Dev\PhpStan\RequireNamedArgumentsRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<RequireNamedArgumentsRule>
 */
final class RequireNamedArgumentsRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new RequireNamedArgumentsRule(self::createReflectionProvider());
    }

    public function testFlagsPositionalMultiArgumentCallsIntoOwnCodeOnly(): void
    {
        // The fixture classes are discoverable through the composer classmap;
        // its standalone function only becomes reflectable once defined.
        require_once __DIR__ . '/Fixtures/require-named-arguments.php';

        $this->analyse([__DIR__ . '/Fixtures/require-named-arguments.php'], [
            [
                'Call to Dan\Harness\Tests\PhpStan\Fixtures\Wallet::__construct() with 2 arguments must use named arguments.',
                41,
            ],
            [
                'Call to Dan\Harness\Tests\PhpStan\Fixtures\Wallet::transfer() with 2 arguments must use named arguments.',
                45,
            ],
            [
                'Call to Dan\Harness\Tests\PhpStan\Fixtures\Wallet::transfer() with 2 arguments must use named arguments.',
                46,
            ],
            [
                'Call to Dan\Harness\Tests\PhpStan\Fixtures\Wallet::compare() with 2 arguments must use named arguments.',
                51,
            ],
            [
                'Call to Dan\Harness\Tests\PhpStan\Fixtures\addFunds() with 2 arguments must use named arguments.',
                54,
            ],
        ]);
    }
}
