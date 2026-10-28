<?php
/**
 * Helper Module Configuration
 * 
 * @llm Companion module for advanced_features demo.
 * Used to demonstrate:
 * - await() - advanced_features waits for this module
 * - addShadowRoute() - routes proxy to this module's closures
 */

return [
    'module_code' => 'system/helper_module',
    'name'        => 'Helper Module',
    'author'      => 'Razy Framework',
    'owner'       => 'Razy Framework', // docs/STYLE.md §0: decides changes to this module and its API.md
    'description' => 'Companion module for await and shadow route demos',
    'version'     => '1.0.0',
];
