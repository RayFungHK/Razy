<?php
/**
 * Template Engine Demo - Variables & Modifiers
 * 
 * @llm Demonstrates variable tags, dot-path access, modifiers, default values,
 * and modifier chaining using live template rendering.
 */

use Razy\Controller;
use Razy\Template;

return function (): void {
    /** @var Controller $this */
    header('Content-Type: application/json; charset=UTF-8');

    $results = [];

    // === Simple Variable Tags ===
    $source = $this->loadTemplate('demo_variables_simple');
    $source->assign([
        'name' => 'Razy Framework',
        'version' => '1.0.1-beta',
        'language' => 'PHP',
    ]);

    $results['simple_variables'] = [
        'description' => 'Basic variable tags: {$name}, {$version}',
        'template' => '{$name} v{$version} — Built with {$language}',
        'data' => ['name' => 'Razy Framework', 'version' => '1.0.1-beta', 'language' => 'PHP'],
        'output' => trim($source->output()),
        'code' => '$source->assign([\'name\' => \'Razy Framework\', \'version\' => \'1.0.1-beta\']);',
    ];

    // === Dot-Path Access ===
    $source = $this->loadTemplate('demo_variables_path');
    $source->assign([
        'user' => [
            'profile' => [
                'name' => 'John Doe',
                'email' => 'john@example.com',
            ],
            'settings' => [
                'theme' => 'dark',
                'lang' => 'en',
            ],
        ],
    ]);

    $results['dot_path_access'] = [
        'description' => 'Access nested data with dot-path: {$user.profile.name}',
        'template' => '{$user.profile.name} ({$user.profile.email}) — Theme: {$user.settings.theme}',
        'data' => [
            'user.profile.name' => 'John Doe',
            'user.profile.email' => 'john@example.com',
            'user.settings.theme' => 'dark',
        ],
        'output' => trim($source->output()),
        'code' => '$source->assign([\'user\' => [\'profile\' => [\'name\' => \'John Doe\', ...], ...]]);',
    ];

    // === Modifiers ===
    $source = $this->loadTemplate('demo_variables_modifiers');
    $source->assign([
        'text' => 'hello world',
        'padded' => '  extra spaces  ',
        'tags' => ['php', 'razy', 'template'],
        'slug_text' => 'Hello World! How are you?',
        'multiline' => "Line one\nLine two\nLine three",
        'quote' => "It's a \"test\" value",
    ]);

    $results['modifiers'] = [
        'description' => 'Transform values with pipe modifiers: {$var|modifier}',
        'template' => '{$text|upper} / {$text|lower} / {$text|capitalize} / {$padded|trim} / {$tags|join:", "} / {$slug_text|alphabet:"-"|lower}',
        'output' => trim($source->output()),
        'code' => '$source->assign([\'text\' => \'hello world\', \'tags\' => [\'php\', \'razy\', \'template\']]);',
    ];

    // === Default Values ===
    $source = $this->loadTemplate('demo_variables_defaults');
    $source->assign([
        'existing' => 'I exist!',
        // 'missing' is intentionally not assigned
    ]);

    $results['default_values'] = [
        'description' => 'Provide fallback with pipe-string: {$var|"fallback"}',
        'template' => 'Existing: {$existing|"N/A"} / Missing: {$missing|"N/A"}',
        'output' => trim($source->output()),
        'code' => '$source->assign([\'existing\' => \'I exist!\']); // $missing not assigned',
    ];

    // === Modifier Chaining ===
    $source = $this->loadTemplate('demo_variables_chain');
    $source->assign([
        'raw_input' => '  hello WORLD  ',
    ]);

    $results['modifier_chaining'] = [
        'description' => 'Chain multiple modifiers: {$var|trim|capitalize}',
        'template' => 'Raw: "{$raw_input}" / Trimmed+Capitalized: {$raw_input|trim|capitalize}',
        'output' => trim($source->output()),
        'code' => '$source->assign([\'raw_input\' => \'  hello WORLD  \']);',
    ];

    // === Type-aware gettype Modifier ===
    $source = $this->loadTemplate('demo_variables_gettype');
    $source->assign([
        'string_val' => 'hello',
        'int_val' => 42,
        'float_val' => 3.14,
        'bool_val' => true,
        'array_val' => [1, 2, 3],
        'null_val' => null,
    ]);

    $results['gettype_modifier'] = [
        'description' => 'Inspect types with {$var|gettype} modifier',
        'template' => '{$string_val|gettype}, {$int_val|gettype}, {$float_val|gettype}, {$bool_val|gettype}, {$array_val|gettype}',
        'output' => trim($source->output()),
        'code' => '$source->assign([\'string_val\' => \'hello\', \'int_val\' => 42, \'array_val\' => [1,2,3]]);',
    ];

    echo json_encode($results, JSON_PRETTY_PRINT);
};
