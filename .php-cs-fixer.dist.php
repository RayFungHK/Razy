<?php
/**
 * PHP-CS-Fixer distribution configuration for the Razy framework.
 *
 * This is the canonical configuration committed to version control.
 * Contributors and CI both use this file.
 *
 * Local overrides: copy to .php-cs-fixer.php (git-ignored).
 *
 * Usage:
 *   composer cs-check   # dry-run — CI uses this (hard fail)
 *   composer cs-fix     # apply fixes locally
 *
 * @see https://cs.symfony.com/doc/rules/index.html
 */

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

$finder = Finder::create()
    ->in([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->exclude([
        'asset',        // Template / scaffold files contain non-PHP syntax
        'plugins',      // Runtime plugins loaded dynamically
        'system',       // CLI entry-points with global scope
    ])
    ->name('*.php')
    ->ignoreDotFiles(true)
    ->ignoreVCS(true);

return (new Config())
    ->setRiskyAllowed(true)
    ->setRules([
        // ── Base rule sets ─────────────────────────────────────
        '@PSR12'          => true,
        '@PSR12:risky'    => true,
        '@PHP8x2Migration' => true,

        // ── Modern PHP syntax ──────────────────────────────────
        'modernize_types_casting' => true,
        'no_useless_else'        => true,
        'no_useless_return'      => true,
        'simplified_null_return'  => true,
        'dir_constant'           => true,
        'is_null'                => true,

        // ── Arrays ─────────────────────────────────────────────
        'array_syntax'       => ['syntax' => 'short'],
        'normalize_index_brace' => true,
        'trim_array_spaces'  => true,
        'no_whitespace_before_comma_in_array' => true,
        'whitespace_after_comma_in_array' => [
            'ensure_single_space' => true,
        ],

        // ── Control structures ─────────────────────────────────
        'no_alternative_syntax'   => true,
        'no_superfluous_elseif'   => true,
        'yoda_style'              => false,
        'control_structure_braces' => true,
        'control_structure_continuation_position' => [
            'position' => 'same_line',
        ],

        // ── Functions & methods ────────────────────────────────
        'function_declaration' => [
            'closure_function_spacing' => 'one',
        ],
        'method_argument_space' => [
            'on_multiline'                     => 'ensure_fully_multiline',
            'keep_multiple_spaces_after_comma'  => false,
        ],
        'native_function_invocation' => [
            'include' => ['@all'],
            'scope'   => 'namespaced',
            'strict'  => false,
        ],
        'return_type_declaration' => ['space_before' => 'none'],
        'trailing_comma_in_multiline' => [
            'elements' => ['arrays', 'arguments', 'parameters'],
        ],
        'no_trailing_comma_in_singleline' => true,

        // ── Classes ────────────────────────────────────────────
        'class_attributes_separation' => [
            'elements' => [
                'const'        => 'one',
                'method'       => 'one',
                'property'     => 'one',
                'trait_import' => 'none',
            ],
        ],
        'class_definition' => [
            'single_line'             => true,
            'single_item_single_line' => true,
        ],
        'no_blank_lines_after_class_opening' => true,
        'ordered_class_elements' => [
            'order' => [
                'use_trait',
                'constant_public',
                'constant_protected',
                'constant_private',
                'property_public_static',
                'property_protected_static',
                'property_private_static',
                'property_public',
                'property_protected',
                'property_private',
                'construct',
                'destruct',
                'magic',
                'phpunit',
                'method_public_static',
                'method_protected_static',
                'method_private_static',
                'method_public',
                'method_protected',
                'method_private',
            ],
        ],
        'self_accessor'       => true,
        'modifier_keywords' => [
            'elements' => ['property', 'method', 'const'],
        ],
        'single_class_element_per_statement' => true,

        // ── Imports ────────────────────────────────────────────
        'fully_qualified_strict_types' => true,
        'global_namespace_import' => [
            'import_classes'   => true,
            'import_constants' => false,
            'import_functions' => false,
        ],
        'no_unused_imports' => true,
        'no_leading_import_slash' => true,
        'ordered_imports'  => [
            'imports_order'  => ['class', 'function', 'const'],
            'sort_algorithm' => 'alpha',
        ],
        'single_line_after_imports' => true,

        // ── Operators ──────────────────────────────────────────
        'binary_operator_spaces' => [
            'operators' => [
                '=>' => 'single_space',
                '='  => 'single_space',
            ],
        ],
        'concat_space'           => ['spacing' => 'one'],
        'operator_linebreak'     => ['only_booleans' => true],
        'ternary_operator_spaces' => true,
        'unary_operator_spaces'  => true,

        // ── PHPDoc ─────────────────────────────────────────────
        'phpdoc_align'   => ['align' => 'left'],
        'phpdoc_indent'  => true,
        'phpdoc_no_access' => true,
        'phpdoc_no_empty_return' => true,
        'phpdoc_scalar'  => true,
        'phpdoc_separation' => true,
        'phpdoc_single_line_var_spacing' => true,
        'phpdoc_summary' => true,
        'phpdoc_to_comment' => false,
        'phpdoc_trim'    => true,
        'phpdoc_types'   => true,
        'phpdoc_types_order' => [
            'null_adjustment' => 'always_last',
            'sort_algorithm'  => 'none',
        ],
        'phpdoc_var_without_name' => true,
        'no_empty_phpdoc' => true,
        'no_blank_lines_after_phpdoc' => true,

        // ── Strict types ───────────────────────────────────────
        'declare_strict_types' => false,
        'declare_equal_normalize' => ['space' => 'none'],

        // ── Strings ────────────────────────────────────────────
        'single_quote' => [
            'strings_containing_single_quote_chars' => false,
        ],
        'string_implicit_backslashes' => true,

        // ── Whitespace ─────────────────────────────────────────
        'blank_line_after_namespace'   => true,
        'blank_line_after_opening_tag' => true,
        'compact_nullable_type_declaration' => true,
        'line_ending'                  => true,
        'no_extra_blank_lines' => [
            'tokens' => [
                'curly_brace_block',
                'extra',
                'parenthesis_brace_block',
                'square_brace_block',
                'throw',
                'use',
            ],
        ],
        'no_spaces_around_offset'       => true,
        'no_trailing_whitespace'        => true,
        'no_trailing_whitespace_in_comment' => true,
        'no_whitespace_in_blank_line'   => true,
        'single_blank_line_at_eof'      => true,

        // ── Casting ────────────────────────────────────────────
        'cast_spaces'      => ['space' => 'single'],
        'lowercase_cast'   => true,
        'short_scalar_cast' => true,

        // ── Comments ───────────────────────────────────────────
        'single_line_comment_style' => ['comment_types' => ['hash']],
        'multiline_comment_opening_closing' => true,

        // ── Language constructs ────────────────────────────────
        'combine_consecutive_issets' => true,
        'combine_consecutive_unsets' => true,
        'include'                    => true,
        'no_empty_statement'         => true,

        // ── PHPUnit ────────────────────────────────────────────
        'php_unit_construct'        => true,
        'php_unit_dedicate_assert'  => ['target' => 'newest'],
        'php_unit_fqcn_annotation'  => true,
        'php_unit_method_casing'    => ['case' => 'camel_case'],
        // Do NOT force test prefix — project uses #[Test] attributes
        'php_unit_test_annotation'  => false,
    ])
    ->setFinder($finder)
    ->setLineEnding("\n")
    ->setCacheFile(__DIR__ . '/.php-cs-fixer.cache');

