<?php
/**
 * Template Engine Demo - Entity API
 * 
 * @llm Demonstrates the Template\Entity API for dynamic block creation,
 * parameter assignment, block counting, named entities, and the
 * parameter resolution chain.
 */

use Razy\Controller;

return function (): void {
    /** @var Controller $this */
    header('Content-Type: application/json; charset=UTF-8');

    $results = [];

    // === newBlock() and assign() ===
    $source = $this->loadTemplate('demo_entity_basic');
    $root = $source->getRoot();

    $root->newBlock('user')->assign(['name' => 'Alice', 'role' => 'Admin']);
    $root->newBlock('user')->assign(['name' => 'Bob', 'role' => 'Editor']);
    $root->newBlock('user')->assign(['name' => 'Charlie', 'role' => 'Viewer']);

    $results['newblock_assign'] = [
        'description' => '$root->newBlock(\'name\')->assign([...]) — Create block instances with data',
        'code' => "\$root->newBlock('user')->assign(['name' => 'Alice', 'role' => 'Admin']);\n\$root->newBlock('user')->assign(['name' => 'Bob', 'role' => 'Editor']);\n\$root->newBlock('user')->assign(['name' => 'Charlie', 'role' => 'Viewer']);",
        'output' => trim($source->output()),
    ];

    // === Named Entities (ID) ===
    $source = $this->loadTemplate('demo_entity_named');
    $root = $source->getRoot();

    // Using the second parameter as ID
    $root->newBlock('tab', 'home')->assign(['label' => 'Home', 'active' => true]);
    $root->newBlock('tab', 'about')->assign(['label' => 'About', 'active' => false]);
    $root->newBlock('tab', 'contact')->assign(['label' => 'Contact', 'active' => false]);

    // Retrieve by ID to modify later
    $aboutTab = $root->getEntity('tab', 'about');
    if ($aboutTab) {
        $aboutTab->assign(['active' => true]); // Override — now both home & about are "active"
    }

    $results['named_entities'] = [
        'description' => 'newBlock(\'name\', \'id\') — Named entities can be retrieved with getEntity()',
        'code' => "\$root->newBlock('tab', 'home')->assign(['label' => 'Home']);\n\$root->newBlock('tab', 'about')->assign(['label' => 'About']);\n// Later: retrieve and modify\n\$aboutTab = \$root->getEntity('tab', 'about');\n\$aboutTab->assign(['active' => true]);",
        'output' => trim($source->output()),
    ];

    // === Block Counting ===
    $source = $this->loadTemplate('demo_entity_count');
    $root = $source->getRoot();
    $source->assign(['total' => 0]); // Will be overridden

    for ($i = 1; $i <= 5; $i++) {
        $root->newBlock('item')->assign(['num' => $i, 'label' => "Item #{$i}"]);
    }
    $source->assign(['total' => $root->getBlockCount('item')]);

    $results['block_counting'] = [
        'description' => '$root->getBlockCount(\'name\') — Count block instances',
        'code' => "for (\$i = 1; \$i <= 5; \$i++) { \$root->newBlock('item')->assign([...]); }\n\$source->assign(['total' => \$root->getBlockCount('item')]); // 5",
        'count' => $root->getBlockCount('item'),
        'output' => trim($source->output()),
    ];

    // === Hierarchical Nested Blocks ===
    $source = $this->loadTemplate('demo_entity_hierarchy');
    $root = $source->getRoot();

    $menu = [
        'Products' => [
            'Software' => ['IDE', 'CMS', 'CRM'],
            'Hardware' => ['Servers', 'Workstations'],
        ],
        'Services' => [
            'Consulting' => ['Strategy', 'Architecture'],
            'Support' => ['Basic', 'Premium', 'Enterprise'],
        ],
    ];

    foreach ($menu as $catName => $groups) {
        $catBlock = $root->newBlock('category')->assign(['name' => $catName]);
        foreach ($groups as $groupName => $items) {
            $groupBlock = $catBlock->newBlock('group')->assign(['name' => $groupName]);
            foreach ($items as $item) {
                $groupBlock->newBlock('item')->assign(['name' => $item]);
            }
        }
    }

    $results['nested_hierarchy'] = [
        'description' => 'Three-level nesting: category → group → item — shows Entity API composability',
        'code' => "\$catBlock = \$root->newBlock('category')->assign([...]);\n\$groupBlock = \$catBlock->newBlock('group')->assign([...]);\n\$groupBlock->newBlock('item')->assign(['name' => 'IDE']);",
        'output' => trim($source->output()),
    ];

    // === Parameter Resolution Chain ===
    $source = $this->loadTemplate('demo_entity_resolution');
    $root = $source->getRoot();

    // Source-level (global default)
    $source->assign([
        'color' => 'blue',
        'size' => 'medium',
        'brand' => 'Razy',
    ]);

    // Block-level override
    $block = $root->newBlock('item')->assign([
        'color' => 'red',
        // 'size' not set — falls through to Source
        // 'brand' not set — falls through to Source
    ]);

    $results['parameter_resolution'] = [
        'description' => 'Entity overrides Source defaults: Entity → Block → Source → Template',
        'code' => "\$source->assign(['color' => 'blue', 'size' => 'medium', 'brand' => 'Razy']);\n\$root->newBlock('item')->assign(['color' => 'red']); // only color overridden",
        'output' => trim($source->output()),
    ];

    // === hasBlock / hasEntity ===
    $source = $this->loadTemplate('demo_entity_checks');
    $root = $source->getRoot();
    $root->newBlock('existing')->assign(['msg' => 'I exist!']);

    $results['existence_checks'] = [
        'description' => 'hasBlock() checks block definition; hasEntity() checks runtime instances',
        'code' => "\$root->hasBlock('existing')  // true (block defined in template)\n\$root->hasBlock('nonexist')  // false\n\$root->hasEntity('existing') // true (entity created via newBlock)\n\$root->hasEntity('nothing')  // false",
        'has_block_existing' => $root->hasBlock('existing'),
        'has_block_nonexist' => $root->hasBlock('nonexist'),
        'has_entity_existing' => $root->hasEntity('existing'),
        'has_entity_nothing' => $root->hasEntity('nothing'),
        'output' => trim($source->output()),
    ];

    echo json_encode($results, JSON_PRETTY_PRINT);
};
