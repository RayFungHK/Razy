<?php
/**
 * Template Engine Demo - Function Tags
 * 
 * @llm Demonstrates @if, @each, @repeat, @def, and @template function tags.
 */

use Razy\Controller;

return function (): void {
    /** @var Controller $this */
    header('Content-Type: application/json; charset=UTF-8');

    $results = [];

    // === @if Conditional ===
    $source = $this->loadTemplate('demo_func_if');
    $source->assign([
        'logged_in' => true,
        'username' => 'admin',
        'role' => 'editor',
        'score' => 85,
    ]);

    $results['if_conditional'] = [
        'description' => '{@if $var="value"}...{@else}...{/if} — Conditional rendering',
        'tpl_code' => '{@if $logged_in}Welcome, {$username}!{@else}Please log in.{/if}',
        'code' => '$source->assign([\'logged_in\' => true, \'username\' => \'admin\']);',
        'output' => trim($source->output()),
    ];

    // === @if with comparison ===
    $source = $this->loadTemplate('demo_func_if_compare');
    $source->assign([
        'status' => 'active',
        'count' => 0,
        'items' => ['a', 'b', 'c'],
    ]);

    $results['if_comparison'] = [
        'description' => '{@if $var="value"} — Compare against string values',
        'tpl_code' => '{@if $status="active"}Active!{@else}Inactive{/if} | {@if $count}Has items{@else}Empty{/if}',
        'code' => '$source->assign([\'status\' => \'active\', \'count\' => 0]);',
        'output' => trim($source->output()),
    ];

    // === @each Iterator ===
    $source = $this->loadTemplate('demo_func_each');
    $source->assign([
        'colors' => ['Red', 'Green', 'Blue', 'Yellow'],
        'users' => [
            ['name' => 'Alice', 'age' => 30],
            ['name' => 'Bob', 'age' => 25],
            ['name' => 'Charlie', 'age' => 35],
        ],
    ]);

    $results['each_iterator'] = [
        'description' => '{@each source=$array as="item"}...{/each} — Iterate over arrays',
        'tpl_code' => '{@each source=$colors as="c"}{$c.value}, {/each}',
        'code' => '$source->assign([\'colors\' => [\'Red\', \'Green\', \'Blue\', \'Yellow\']]);',
        'output' => trim($source->output()),
    ];

    // === @each with key-value pairs ===
    $source = $this->loadTemplate('demo_func_each_kvp');
    $source->assign([
        'settings' => [
            'theme' => 'dark',
            'language' => 'en',
            'timezone' => 'UTC',
        ],
    ]);

    $results['each_key_value'] = [
        'description' => '{@each $assocArray} — Default kvp.key and kvp.value access',
        'tpl_code' => '{@each $settings}{$kvp.key}: {$kvp.value}; {/each}',
        'code' => '$source->assign([\'settings\' => [\'theme\' => \'dark\', \'language\' => \'en\', \'timezone\' => \'UTC\']]);',
        'output' => trim($source->output()),
    ];

    // === @repeat ===
    $source = $this->loadTemplate('demo_func_repeat');
    $source->assign([
        'star' => '★',
    ]);

    $results['repeat'] = [
        'description' => '{@repeat length=N}...{/repeat} — Repeat content N times',
        'tpl_code' => 'Rating: {@repeat length=5}{$star}{/repeat}',
        'code' => '$source->assign([\'star\' => \'★\']);',
        'output' => trim($source->output()),
    ];

    // === @def Variable Definition ===
    $source = $this->loadTemplate('demo_func_def');
    $source->assign([
        'base_url' => 'https://example.com',
    ]);

    $results['def_variable'] = [
        'description' => '{@def "name" "value"} — Define template variables inline',
        'tpl_code' => '{@def "greeting" "Hello World"}{@def "site" $base_url}{$greeting} — {$site}',
        'code' => '$source->assign([\'base_url\' => \'https://example.com\']); // greeting defined in template',
        'output' => trim($source->output()),
    ];

    // === @template (inline named template) ===
    $source = $this->loadTemplate('demo_func_template');
    $source->assign([
        'cards' => [
            ['title' => 'Feature A', 'icon' => '🚀'],
            ['title' => 'Feature B', 'icon' => '⚡'],
            ['title' => 'Feature C', 'icon' => '🎯'],
        ],
    ]);

    $results['template_tag'] = [
        'description' => '{@template:Name param=$var} — Render a named TEMPLATE block with parameters',
        'tpl_code' => "<!-- TEMPLATE BLOCK: badge -->\n<span>[{{\$icon}} {{\$title}}]</span>\n<!-- END BLOCK: badge -->\n\n{@each source=\$cards as=\"c\"}{@template:badge icon=\$c.value.icon title=\$c.value.title}{/each}",
        'code' => '$source->assign([\'cards\' => [[\'title\' => \'Feature A\', \'icon\' => \'🚀\'], ...]]);',
        'output' => trim($source->output()),
    ];

    echo json_encode($results, JSON_PRETTY_PRINT);
};
