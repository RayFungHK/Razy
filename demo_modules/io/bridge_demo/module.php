<?php
/**
 * Bridge Demo Module Configuration
 * 
 * @llm Module demonstrating cross-distributor communication via internal bridge.
 * Shows how to call APIs in siteB from testsite without class conflicts.
 */

return [
    'module_code' => 'io/bridge_demo',
    'name'        => 'Cross-Distributor Bridge Demo',
    'author'      => 'Razy Framework',
    'owner'       => 'Razy Framework', // docs/STYLE.md §0: decides changes to this module and its API.md
    'description' => 'Demonstrates cross-distributor API calls via internal bridge',
    'version'     => '1.0.0',
];
