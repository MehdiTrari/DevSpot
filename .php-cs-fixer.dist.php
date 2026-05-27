<?php

declare(strict_types=1);

$finder = (new PhpCsFixer\Finder())
    ->in([
        __DIR__.'/bin',
        __DIR__.'/config',
        __DIR__.'/src',
    ])
    ->exclude([
        'var',
        'vendor',
    ])
    ->notPath('config/bundles.php')
    ->notPath('config/reference.php')
    ->notPath('src/Kernel.php')
;

return (new PhpCsFixer\Config())
    ->setRules([
        '@PSR12' => true,
        'binary_operator_spaces' => [
            'default' => 'single_space',
        ],
        'blank_line_after_opening_tag' => true,
        'concat_space' => [
            'spacing' => 'one',
        ],
        'declare_strict_types' => false,
        'native_constant_invocation' => false,
        'native_function_invocation' => false,
        'ordered_imports' => false,
        'single_import_per_statement' => false,
        'yoda_style' => false,
    ])
    ->setFinder($finder)
;