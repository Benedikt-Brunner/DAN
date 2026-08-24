<?php

declare(strict_types=1);

namespace Dan\Probe\Tests\Arch;

use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

/**
 * The probe's side of AGENTS.md's inviolable dependency directions, as a
 * phpat rule inside the bundle's PHPStan gate (registered in
 * bundle/phpstan.neon.dist). The probe's vendor platform - Shopware,
 * Symfony, Doctrine - is its runtime and stays legal; among DAN's own
 * namespaces only the lib is. The harness-side rules live in the root
 * tests/Arch, where the harness and lib sources are analyzable.
 */
final class DependencyDirectionRules
{
    public function testTheProbeOnlyUsesTheLibAmongDanNamespaces(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('Dan\Probe'))
            ->canOnly()->dependOn()
            ->classes(
                Selector::inNamespace('Dan\Probe'),
                Selector::inNamespace('Dan\Lib'),
                // Anything outside DAN's own namespaces is vendor territory
                // and governed by composer.json, not by this rule.
                Selector::classname('/^(?!Dan\\\\)/', true),
            )
            ->because('the probe runs inside DAL runtimes and may share only the lib with the harness (AGENTS.md, dependency direction)');
    }
}
