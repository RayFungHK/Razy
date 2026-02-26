<?php
/**
 * Plugin System Overview
 * 
 * @llm Explains the plugin architecture and system.
 */

use Razy\Controller;

return function (): void {
    header('Content-Type: application/json; charset=UTF-8');
    /** @var Controller $this */
    
    echo json_encode([
        'plugin_systems' => [
            'template' => [
                'description' => 'Extends template engine',
                'folder' => 'src/plugins/Template/',
                'types' => [
                    'function.NAME.php' => 'Template functions like {if}, {foreach}',
                    'modifier.NAME.php' => 'Value modifiers like |upper, |escape',
                ],
                'base_classes' => [
                    'BlockFunction' => 'For block-style functions {func}...{/func}',
                    'InlineFunction' => 'For inline {func arg="val"}',
                ],
            ],
            'collection' => [
                'description' => 'Filters and transforms Collection data',
                'folder' => 'src/plugins/Collection/',
                'types' => [
                    'filter.NAME.php' => 'Filter matched elements :istype(string)',
                    'processor.NAME.php' => 'Transform values ->trim()',
                ],
            ],
            'flowmanager' => [
                'description' => 'Data flow state processing',
                'folder' => 'src/plugins/FlowManager/',
                'types' => [
                    'NAME.php' => 'Flow handlers like FormWorker',
                ],
            ],
            'statement' => [
                'description' => 'Database query extensions',
                'folder' => 'src/plugins/Statement/',
                'types' => [
                    'NAME.php' => 'Query builder plugins',
                ],
            ],
        ],
        
        'plugin_trait_api' => [
            'AddPluginFolder' => [
                'signature' => 'static function AddPluginFolder(string $folder, mixed $args = null): void',
                'purpose' => 'Register a folder containing plugins',
            ],
            'GetPlugin' => [
                'signature' => 'static private function GetPlugin(string $pluginName): ?array',
                'purpose' => 'Load and cache plugin by name',
            ],
        ],
        
        'built_in_plugins' => [
            'template_functions' => [
                'if', 'foreach', 'require', 'assign', 'block', 'extends',
                'include', 'loop', 'raw', 'recursion', 'while',
            ],
            'template_modifiers' => [
                'assign', 'block', 'capital', 'count', 'default', 'escape',
                'format', 'htmlspecialchars', 'join', 'json', 'length',
                'lower', 'match', 'md5', 'nl2br', 'number', 'replace',
                'reverse', 'sha256', 'split', 'strip', 'type', 'upper', 'wordwrap',
            ],
            'collection_filters' => [
                'istype' => 'Filter by PHP type',
            ],
            'collection_processors' => [
                'trim' => 'Trim whitespace',
                'int' => 'Convert to integer',
                'float' => 'Convert to float',
            ],
        ],
    ], JSON_PRETTY_PRINT);
};
