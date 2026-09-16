<?php

/**
 * PHP-CS-Fixer distribution configuration for the Razy framework.
 *
 * This is the canonical configuration committed to version control —
 * contributors and CI both use this file. php-cs-fixer falls back to
 * .dist.php when no local .php-cs-fixer.php exists; the local fork that
 * lived beside this file for months is RETIRED (deleted) — CI and local
 * now run ONE ruleset.
 *
 * History note (2026-09, CI-reddening cleanup): the previous committed dist
 * config drifted away from the ruleset the code actually evolved under for
 * seven months — CI checked ~16 rules local never ran (@PHP8x2Migration,
 * trailing_comma_in_multiline, phpdoc_types_order, …) while local never
 * covered the modules/ tree CI checked. This file keeps the living ruleset
 * (the local one, verbatim) and GAINS the modules/ finder path from the old
 * dist. Reviving the retired historical rules, if ever wanted, is its own
 * style commit — not a CI-repair side effect.
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
        __DIR__ . '/modules',   // first-party module sources are product code, same style bar
    ])
    ->exclude([
        'asset',        // Template / scaffold files contain non-PHP syntax
        'plugins',      // Runtime plugins loaded dynamically
        'system',       // CLI entry-points with global scope
    ])
    ->name('*.php')
    ->notName('*.blade.php')
    ->ignoreDotFiles(true)
    ->ignoreVCS(true);

return (new Config())
    ->setRiskyAllowed(true)
    ->setRules([
        // PSR Standards
        '@PSR12' => true,                    // PSR-12 Extended Coding Style
        '@PSR12:risky' => true,              // PSR-12 risky rules

        // PHP 8.2+ Modern Syntax
        'modernize_types_casting' => true,   // Use (int) instead of intval()
        'no_useless_else' => true,           // Remove useless else
        'no_useless_return' => true,         // Remove useless return
        'simplified_null_return' => true,    // Simplify null returns

        // Arrays
        'array_syntax' => ['syntax' => 'short'],  // Use [] instead of array()
        'normalize_index_brace' => true,          // Use [] for array access
        'whitespace_after_comma_in_array' => ['ensure_single_space' => true],
        'trim_array_spaces' => true,
        'no_whitespace_before_comma_in_array' => true,

        // Control Structures
        'no_alternative_syntax' => true,          // No if(): endif; syntax
        'no_superfluous_elseif' => true,
        'yoda_style' => false,                    // Allow $var == 'value' (not 'value' == $var)
        'control_structure_braces' => true,       // Braces on correct position
        'control_structure_continuation_position' => ['position' => 'same_line'],

        // Functions
        'function_declaration' => ['closure_function_spacing' => 'one'],
        'method_argument_space' => [
            'on_multiline' => 'ensure_fully_multiline',
            'keep_multiple_spaces_after_comma' => false,
        ],
        'native_function_invocation' => [         // Add \ to native functions for performance
            'include' => ['@all'],
            'scope' => 'namespaced',
            'strict' => false,
        ],
        'no_spaces_after_function_name' => true,
        'return_type_declaration' => ['space_before' => 'none'],

        // Classes
        'class_attributes_separation' => [
            'elements' => [
                'const' => 'one',
                'method' => 'one',
                'property' => 'one',
                'trait_import' => 'none',
            ],
        ],
        'class_definition' => [
            'single_line' => true,
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
        'self_accessor' => true,                  // Use self:: instead of ClassName::
        'modifier_keywords' => ['elements' => ['property', 'method', 'const']],

        // Imports
        'fully_qualified_strict_types' => true,
        'global_namespace_import' => [
            'import_classes' => true,
            'import_constants' => false,
            'import_functions' => false,
        ],
        'no_unused_imports' => true,
        'ordered_imports' => [
            'imports_order' => ['class', 'function', 'const'],
            'sort_algorithm' => 'alpha',
        ],
        'single_line_after_imports' => true,

        // Operators
        'binary_operator_spaces' => [
            'operators' => [
                '=>' => 'single_space',
                '=' => 'single_space',
            ],
        ],
        'concat_space' => ['spacing' => 'one'],   // String concatenation spacing
        'operator_linebreak' => ['only_booleans' => true],
        'ternary_operator_spaces' => true,
        'unary_operator_spaces' => true,

        // PHPDoc
        'phpdoc_align' => ['align' => 'left'],
        'phpdoc_indent' => true,
        'phpdoc_no_access' => true,               // Remove @access tag
        'phpdoc_no_empty_return' => true,         // Remove @return void
        'phpdoc_no_package' => true,              // Remove @package tag
        'phpdoc_scalar' => true,                  // Use int not integer
        'phpdoc_separation' => true,
        'phpdoc_single_line_var_spacing' => true,
        'phpdoc_summary' => true,
        'phpdoc_to_comment' => false,             // Allow docblocks without tags
        'phpdoc_trim' => true,
        'phpdoc_types' => true,
        'phpdoc_var_without_name' => true,

        // Strict Types
        'declare_strict_types' => false,          // Don't force declare(strict_types=1)

        // Strings
        'single_quote' => ['strings_containing_single_quote_chars' => false],
        'string_implicit_backslashes' => true,

        // Whitespace
        'blank_line_after_namespace' => true,
        'blank_line_after_opening_tag' => true,
        'compact_nullable_type_declaration' => true, // Use ?int not int|null
        'line_ending' => true,
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
        'no_spaces_around_offset' => true,
        'no_trailing_whitespace' => true,
        'no_trailing_whitespace_in_comment' => true,
        'single_blank_line_at_eof' => true,

        // Casting
        'cast_spaces' => ['space' => 'single'],
        'lowercase_cast' => true,
        'short_scalar_cast' => true,              // Use (int) not (integer)

        // Comments
        'single_line_comment_style' => ['comment_types' => ['hash']],
        'multiline_comment_opening_closing' => true,

        // Language Constructs
        'combine_consecutive_issets' => true,
        'combine_consecutive_unsets' => true,
        'declare_equal_normalize' => ['space' => 'none'],
        'dir_constant' => true,                   // Use __DIR__ not dirname(__FILE__)
        'include' => true,
        'is_null' => true,                        // Use === null not is_null()

        // PHP Unit (for tests)
        'php_unit_construct' => true,
        'php_unit_dedicate_assert' => ['target' => 'newest'],
        'php_unit_fqcn_annotation' => true,
        'php_unit_method_casing' => ['case' => 'camel_case'],
        'php_unit_test_annotation' => ['style' => 'prefix'],
    ])
    ->setFinder($finder)
    ->setLineEnding("\n");
