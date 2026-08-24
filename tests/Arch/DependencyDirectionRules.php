<?php

declare(strict_types=1);

namespace Dan\Harness\Tests\Arch;

use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

/**
 * AGENTS.md's inviolable dependency directions for the harness and the lib,
 * as phpat rules inside the PHPStan gate (registered in phpstan.neon.dist).
 * The probe's side lives in bundle/tests/Arch - its sources are only
 * analyzable against the bundle's vendor dir.
 */
final class DependencyDirectionRules
{
    public function testTheHarnessNeverDependsOnShopware(): Rule
    {
        // Redundant with the allowlist while Shopware is unresolvable at the root - the guard for when it is not.
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
                // Outside DAN's namespaces is vendor territory, governed by composer.json.
                Selector::classname('/^(?!Dan\\\\)/', true),
            )
            ->because('a deny-list alone would let a new (or misspelled) Dan namespace slip through (AGENTS.md, dependency direction)');
    }

    public function testTheLibDependsOnNoOtherPackageAndNoFramework(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('Dan\Lib'))
            ->canOnly()->dependOn()
            ->classes(Selector::inNamespace('Dan\Lib'))
            ->because('the lib must load everywhere the probe does - framework-freedom by construction (AGENTS.md, dependency direction)');
    }

    public function testSymfonyProcessStaysBehindTheProcessRunnerBoundary(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::AllOf(
                Selector::inNamespace('Dan\Harness'),
                Selector::NOT(Selector::inNamespace('Dan\Harness\Process')),
            ))
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace('Symfony\Component\Process'))
            ->because('every external command runs through the ProcessRunner abstraction (AGENTS.md, class and service design)');
    }
}
