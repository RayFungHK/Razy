<?php
/**
 * Template Engine Demo - Block System
 * 
 * @llm Demonstrates START/END blocks, WRAPPER blocks, TEMPLATE blocks,
 * and USE blocks with live template rendering.
 */

use Razy\Controller;

return function (): void {
    /** @var Controller $this */
    header('Content-Type: application/json; charset=UTF-8');

    $results = [];

    // === START/END Block (repeatable) ===
    $source = $this->loadTemplate('demo_blocks_start');
    $root = $source->getRoot();

    $fruits = ['Apple', 'Banana', 'Cherry', 'Date'];
    foreach ($fruits as $fruit) {
        $root->newBlock('item')->assign(['name' => $fruit]);
    }

    $results['start_end_block'] = [
        'description' => 'START/END blocks repeat once per newBlock() call',
        'tpl_code' => "<!-- START BLOCK: item -->\n<li>{{\$name}}</li>\n<!-- END BLOCK: item -->",
        'code' => 'foreach ([\'Apple\', \'Banana\', \'Cherry\'] as $fruit) { $root->newBlock(\'item\')->assign([\'name\' => $fruit]); }',
        'data' => $fruits,
        'output' => trim($source->output()),
    ];

    // === WRAPPER Block ===
    $source = $this->loadTemplate('demo_blocks_wrapper');
    $root = $source->getRoot();

    $tags = [
        ['name' => 'PHP', 'color' => '#777bb3'],
        ['name' => 'JavaScript', 'color' => '#f7df1e'],
        ['name' => 'MySQL', 'color' => '#00758f'],
        ['name' => 'Redis', 'color' => '#dc382d'],
    ];
    foreach ($tags as $tag) {
        $root->newBlock('tag')->assign($tag);
    }

    $results['wrapper_block'] = [
        'description' => 'WRAPPER wraps ALL block instances in a container (appears once)',
        'tpl_code' => "<!-- WRAPPER BLOCK: tag -->\n<div class=\"tag-cloud\">\n  <!-- START BLOCK: tag -->\n  <span style=\"color:{{\$color}}\">{{\$name}}</span>\n  <!-- END BLOCK: tag -->\n</div>\n<!-- END BLOCK: tag -->",
        'code' => 'foreach ($tags as $tag) { $root->newBlock(\'tag\')->assign($tag); }',
        'output' => trim($source->output()),
    ];

    // === TEMPLATE + USE Block ===
    $source = $this->loadTemplate('demo_blocks_template_use');
    $root = $source->getRoot();

    // Create items that USE the inline template
    $root->newBlock('card')->assign(['title' => 'Dashboard', 'desc' => 'Overview panel']);
    $root->newBlock('card')->assign(['title' => 'Settings', 'desc' => 'Configuration page']);
    $root->newBlock('card')->assign(['title' => 'Reports', 'desc' => 'Analytics data']);

    $results['template_use_block'] = [
        'description' => 'TEMPLATE defines reusable content; USE references it inside another block',
        'tpl_code' => "<!-- TEMPLATE BLOCK: card_tpl -->\n<div class=\"card\"><h3>{{\$title}}</h3><p>{{\$desc}}</p></div>\n<!-- END BLOCK: card_tpl -->\n\n<!-- START BLOCK: card -->\n  <!-- USE card_tpl BLOCK: content -->\n<!-- END BLOCK: card -->",
        'code' => '$root->newBlock(\'card\')->assign([\'title\' => \'Dashboard\', \'desc\' => \'Overview panel\']);',
        'output' => trim($source->output()),
    ];

    // === No blocks created = no output ===
    $source = $this->loadTemplate('demo_blocks_empty');

    $results['empty_blocks'] = [
        'description' => 'If no newBlock() is called, START/END blocks produce no output',
        'tpl_code' => "Before\n<!-- START BLOCK: item -->\n{{\$name}}\n<!-- END BLOCK: item -->\nAfter",
        'code' => '// No $root->newBlock(\'item\') — block section is skipped',
        'output' => trim($source->output()),
    ];

    // === Nested Blocks ===
    $source = $this->loadTemplate('demo_blocks_nested');
    $root = $source->getRoot();

    $categories = [
        'Fruits' => ['Apple', 'Banana', 'Cherry'],
        'Vegetables' => ['Carrot', 'Broccoli'],
    ];
    foreach ($categories as $catName => $items) {
        $catBlock = $root->newBlock('category')->assign(['name' => $catName]);
        foreach ($items as $item) {
            $catBlock->newBlock('item')->assign(['name' => $item]);
        }
    }

    $results['nested_blocks'] = [
        'description' => 'Blocks can nest inside blocks for hierarchical data',
        'tpl_code' => "<!-- START BLOCK: category -->\n<div><strong>{{\$name}}</strong><ul>\n  <!-- START BLOCK: item -->\n  <li>{{\$name}}</li>\n  <!-- END BLOCK: item -->\n</ul></div>\n<!-- END BLOCK: category -->",
        'code' => '$catBlock = $root->newBlock(\'category\')->assign([...]); $catBlock->newBlock(\'item\')->assign([...]);',
        'data' => $categories,
        'output' => trim($source->output()),
    ];

    echo json_encode($results, JSON_PRETTY_PRINT);
};
