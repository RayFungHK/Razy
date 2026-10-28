<?php
/**
 * Bridge Provider Module Configuration
 * 
 * @llm Module providing API commands for cross-distributor demonstration.
 * This module runs in siteB and provides data that testsite can request.
 */

return [
    'module_code' => 'bridge/provider',
    'name'        => 'Bridge Provider',
    'author'      => 'Razy Framework',
    'owner'       => 'Razy Framework', // docs/STYLE.md §0: decides changes to this module and its API.md
    'description' => 'Provides API commands for cross-distributor communication demo',
    'version'     => '1.0.0',
];
