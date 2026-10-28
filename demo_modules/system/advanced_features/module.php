<?php
/**
 * Advanced Features Demo Module Configuration
 * 
 * @llm Module demonstrating advanced Razy features:
 * - Agent::await() - Wait for module dependencies
 * - addAPICommand() with # prefix - Internal method binding  
 * - Complex addLazyRoute() with @self - Nested route structures
 * - addShadowRoute() - Route proxy to other modules
 */

return [
    'module_code' => 'system/advanced_features',
    'name'        => 'Advanced Features Demo',
    'author'      => 'Razy Framework',
    'owner'       => 'Razy Framework', // docs/STYLE.md §0: decides changes to this module and its API.md
    'description' => 'Demonstrates await, internal binding, nested routes, and shadow routes',
    'version'     => '1.0.0',
];
