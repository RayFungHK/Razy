<?php

/**
 * Get Markdown Service Info API
 * 
 * Returns information about the markdown service and library version.
 * 
 * USAGE:
 *   $info = $this->api('markdown')->getInfo();
 * 
 * This is useful for debugging and verifying which library version is loaded.
 */

use League\CommonMark\CommonMarkConverter;

return function (): array {
    // Get the library version using Composer's installed.json or class constants
    $version = 'unknown';
    
    // Try to get version from class constant if available
    if (defined('League\\CommonMark\\CommonMarkConverter::VERSION')) {
        $version = CommonMarkConverter::VERSION;
    } else {
        // Fallback: check if class exists
        $version = class_exists(CommonMarkConverter::class) ? '2.x (class loaded)' : 'not loaded';
    }
    
    return [
        'service' => 'markdown_service',
        'description' => 'Markdown parsing service using league/commonmark',
        'library' => 'league/commonmark',
        'library_version' => $version,
        'features' => [
            'commonmark' => true,
            'gfm' => true,  // GitHub Flavored Markdown
            'tables' => true,
            'strikethrough' => true,
            'autolinks' => true,
            'task_lists' => true,
        ],
        'api_methods' => [
            'parse' => 'Convert markdown string to HTML',
            'parseFile' => 'Convert markdown file to HTML',
            'getInfo' => 'Get service information (this method)',
        ],
        'version_conflict_solution' => [
            'pattern' => 'Shared Service Pattern',
            'description' => 'All modules that need markdown parsing should depend on this service module instead of declaring the library directly. This ensures only ONE version of the library is loaded.',
            'usage' => '$this->api("markdown")->parse($text)',
        ],
    ];
};
