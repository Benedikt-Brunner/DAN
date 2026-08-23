<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;
use PhpCsFixer\Runner\Parallel\ParallelConfigFactory;

$finder = (new Finder())
    ->in([__DIR__ . '/src', __DIR__ . '/lib/src', __DIR__ . '/bundle/src', __DIR__ . '/dev', __DIR__ . '/tests', __DIR__ . '/bundle/tests'])
    // PHPStan rule-test fixtures must keep their exact formatting; expected
    // error line numbers in the tests depend on it.
    ->exclude('PhpStan/Fixtures')
    ->append([__FILE__, __DIR__ . '/bin/dan']);

// Base: PER-CS 2.0 + Symfony's rule set. DAN-specific rules below - this
// config is deliberately self-contained and does not inherit from other
// projects.
return (new Config())
    ->setParallelConfig(ParallelConfigFactory::detect())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PER-CS2.0' => true,
        '@Symfony' => true,
        '@Symfony:risky' => true,

        // --- Project-specific rules ---
        'declare_strict_types' => true,
        'concat_space' => ['spacing' => 'one'],
        'global_namespace_import' => ['import_classes' => true, 'import_constants' => false, 'import_functions' => false],
        'multiline_whitespace_before_semicolons' => ['strategy' => 'no_multi_line'],
        'native_function_invocation' => false,
        'ordered_imports' => ['imports_order' => ['class', 'function', 'const'], 'sort_algorithm' => 'alpha'],
        'phpdoc_align' => ['align' => 'left'],
        'phpdoc_to_comment' => false,
        'single_line_empty_body' => true,
        'static_lambda' => false,
        'trailing_comma_in_multiline' => ['elements' => ['arguments', 'array_destructuring', 'arrays', 'match', 'parameters']],
        'yoda_style' => false,
    ])
    ->setFinder($finder);
