<?php

declare(strict_types=1);

namespace Dan\Harness\Tests\Arch;

use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

/**
 * Enforces AGENTS.md's inviolable dependency directions as phpat rules
 * inside the PHPStan gate (registered in phpstan.neon.dist). phpat works on
 * PHPStan's parsed relations - extends, implements, parameter/return/property
 * types, instantiations, static calls, catches, attributes, doc types - so
 * unlike pest's arch() it does not need the target namespace to be present
 * in the root vendor dir, and grouped or aliased imports are the parser's
 * problem, not ours.
 *
 * Division of labor with the rest of the gate (each case verified by
 * injection): an import that is never used is removed by php-cs-fixer
 * (no_unused_imports); any *used* reference to a class PHPStan cannot
 * resolve - Shopware at the root, whatever its spelling - is a level-max
 * unknown-class error AND an allowlist violation below, because an
 * unresolvable class matches no allowlist selector; and a resolvable
 * dependency on the wrong Dan namespace, which plain PHPStan would accept,
 * is caught by the allowlist rule. The probe's own direction rule lives in
 * bundle/tests/Arch - its sources are only analyzable against the bundle's
 * vendor dir.
 */
final class DependencyDirectionRules
{
    public function testTheHarnessNeverDependsOnShopware(): Rule
    {
        // Today Shopware is unresolvable at the root, so any reference is
        // already an unknown-class error plus an allowlist violation; this
        // rule is the guard for the day shopware/* appears in the root
        // vendor dir, where both of those would fall silent.
        return PHPat::rule()
            ->classes(Selector::inNamespace('Dan\Harness'))
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace('Shopware'))
            ->because('the harness orchestrates DAL runtimes from outside; only the probe runs inside one (AGENTS.md, dependency direction)');
    }

    public function testTheHarnessOnlyUsesTheLibAmongDanNamespaces(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('Dan\Harness'))
            ->canOnly()->dependOn()
            ->classes(
                Selector::inNamespace('Dan\Harness'),
                Selector::inNamespace('Dan\Lib'),
                // Anything outside DAN's own namespaces is vendor territory
                // and governed by composer.json, not by these rules.
                Selector::classname('/^(?!Dan\\\\)/', true),
            )
            ->because('a deny-list alone would let a new (or misspelled) Dan namespace slip through (AGENTS.md, dependency direction)');
    }

    public function testTheLibDependsOnNoOtherPackageAndNoFramework(): Rule
    {
        // PHP built-in and extension classes are exempt globally
        // (phpat.ignore_built_in_classes in phpstan.neon.dist).
        return PHPat::rule()
            ->classes(Selector::inNamespace('Dan\Lib'))
            ->canOnly()->dependOn()
            ->classes(Selector::inNamespace('Dan\Lib'))
            ->because('the lib must load everywhere the probe does - framework-freedom by construction (AGENTS.md, dependency direction)');
    }
}
