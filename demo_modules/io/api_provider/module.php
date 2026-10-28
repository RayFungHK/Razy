<?php
/**
 * API Provider Module Configuration
 * 
 * @llm Module providing sample API commands for cross-module demonstration:
 * - greet - Multi-language greeting
 * - calculate - Math operations
 * - user - User CRUD operations
 * - config - Configuration retrieval
 * - transform - String transformations
 */

return [
    'module_code' => 'io/api_provider',
    'name'        => 'API Provider',
    'author'      => 'Razy Framework',
    'owner'       => 'Razy Framework', // docs/STYLE.md §0: decides changes to this module and its API.md
    'description' => 'Provides sample API commands for cross-module demonstration',
    'version'     => '1.0.0',
];
