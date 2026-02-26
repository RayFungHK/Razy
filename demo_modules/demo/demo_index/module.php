<?php
/**
 * Demo Index Module
 * 
 * @llm Provides the main index page for all demo modules.
 * Uses events for demo registration and API for shared styling.
 * 
 * Routes:
 * - / (root) - Main index page showing all registered demos
 * 
 * Events:
 * - demo/demo_index:register_demo - Fired to collect demo info from all modules
 * 
 * API:
 * - header - Returns shared header HTML with navigation
 * - styles - Returns shared CSS styles
 */
return [
    'module_code' => 'demo/demo_index',
    'name'        => 'Demo Index Module',
    'author'      => 'Razy Framework',
    'description' => 'Central index for all demo modules with shared styling',
    'version'     => '1.0.0',
];
