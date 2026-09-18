<?php

/**
 * Parse Markdown File API
 * 
 * Reads a markdown file and converts it to HTML.
 * 
 * USAGE:
 *   $result = $this->api('markdown')->parseFile('/path/to/file.md');
 *   $result = $this->api('markdown')->parseFile($path, ['gfm' => true]);
 */

use League\CommonMark\GithubFlavoredMarkdownConverter;
use League\CommonMark\CommonMarkConverter;

return function (string $filePath, array $options = []): array {
    // Security: Only allow files within the application
    if (!file_exists($filePath)) {
        return [
            'success' => false,
            'error' => 'File not found: ' . $filePath,
            'html' => '',
        ];
    }
    
    if (!is_readable($filePath)) {
        return [
            'success' => false,
            'error' => 'File not readable: ' . $filePath,
            'html' => '',
        ];
    }
    
    $markdown = file_get_contents($filePath);
    
    if ($markdown === false) {
        return [
            'success' => false,
            'error' => 'Failed to read file: ' . $filePath,
            'html' => '',
        ];
    }
    
    // Default configuration
    $config = [
        'html_input' => $options['html_input'] ?? 'escape',
        'allow_unsafe_links' => $options['allow_unsafe_links'] ?? false,
        'max_nesting_level' => $options['max_nesting_level'] ?? PHP_INT_MAX,
    ];
    
    $useGfm = $options['gfm'] ?? true;
    
    try {
        if ($useGfm) {
            $converter = new GithubFlavoredMarkdownConverter($config);
        } else {
            $converter = new CommonMarkConverter($config);
        }
        
        $html = $converter->convert($markdown)->getContent();
        
        return [
            'success' => true,
            'html' => $html,
            'file' => $filePath,
            'source_length' => strlen($markdown),
            'result_length' => strlen($html),
            'gfm' => $useGfm,
        ];
    } catch (\Throwable $e) {
        return [
            'success' => false,
            'error' => $e->getMessage(),
            'file' => $filePath,
            'html' => '',
        ];
    }
};
