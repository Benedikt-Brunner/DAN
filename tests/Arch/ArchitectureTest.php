<?php

declare(strict_types=1);

// Architecture rules that pest's arch() can genuinely prove. arch() resolves
// namespaces through the root Composer autoloader, so it only sees names that
// are autoloadable here - references to namespaces absent from the root
// vendor dir (Shopware, the probe's Doctrine) are invisible to it. The
// cross-package dependency directions therefore live in the phpat rules
// inside the PHPStan gates (tests/Arch/DependencyDirectionRules.php and
// bundle/tests/Arch/DependencyDirectionRules.php), which work on parsed
// relations instead. Every rule below is proven non-vacuous: adding the
// forbidden import makes it fail.
//
// These are Pest-DSL tests (arch() has no PHPUnit form); they live in their
// own "arch" test suite so Infection's PHPUnit adapter - which cannot load
// class-less Pest files - runs the "unit" suite only. Mutation coverage is
// meaningless for import rules, so nothing is lost.
//
// `Dan\Probe` resolves through a root autoload-dev mapping that exists purely
// so this static analysis can see the bundle's sources. The probe remains a
// separate Composer package and is never instantiable here - its Shopware
// dependencies are not installed at the root.

arch('the probe never depends on the harness', function (): void {
    expect('Dan\Probe')->not->toUse('Dan\Harness');
});

arch('the lib depends on nothing the root autoloader can resolve', function (): void {
    expect('Dan\Lib')->toOnlyUse('Dan\Lib');
});

arch('symfony process stays behind the process-runner boundary', function (): void {
    expect('Symfony\Component\Process')->toOnlyBeUsedIn('Dan\Harness\Process');
});
