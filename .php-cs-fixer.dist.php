<?php

declare(strict_types=1);

$finder = (new PhpCsFixer\Finder())
    ->in([__DIR__ . '/src', __DIR__ . '/tests', __DIR__ . '/examples'])
    ->append([__FILE__]);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setParallelConfig(PhpCsFixer\Runner\Parallel\ParallelConfigFactory::detect())
    ->setRules([
        '@PER-CS2.0' => true,
        '@Symfony' => true,
        '@Symfony:risky' => true,
        'declare_strict_types' => true,
        'concat_space' => ['spacing' => 'one'],
        'global_namespace_import' => false,
        'phpdoc_to_comment' => false,
        'phpdoc_align' => ['align' => 'left'],
        'php_unit_test_case_static_method_calls' => ['call_type' => 'self'],
        'php_unit_method_casing' => ['case' => 'camel_case'],
        'single_line_throw' => false,
        'trailing_comma_in_multiline' => ['elements' => ['arguments', 'arrays', 'match', 'parameters']],
    ])
    ->setFinder($finder);
