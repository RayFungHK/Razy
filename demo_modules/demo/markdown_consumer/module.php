<?php

/**
 * Markdown Consumer Module
 * 
 * This module demonstrates the CORRECT way to use a shared library
 * when there are potential version conflicts.
 * 
 * WRONG (causes version conflict):
 *   This module declares: 'league/commonmark' => '^1.0'
 *   Another module declares: 'league/commonmark' => '^2.0'
 *   Result: Only one version can load, one module breaks
 * 
 * RIGHT (no conflict):
 *   This module declares: 'system/markdown_service' (required module)
 *   Uses API: $this->api('markdown')->parse($text)
 *   Result: Service handles the library, version managed centrally
 */

return [
    'module_code' => 'demo/markdown_consumer',
    'name' => 'Markdown Consumer',
    'author' => 'Razy Framework',
    'description' => 'Demonstrates using markdown_service to avoid version conflicts',
    'version' => '1.0.0',
];
