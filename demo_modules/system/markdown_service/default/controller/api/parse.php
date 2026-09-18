<?php

/**
 * Markdown Parse API
 * 
 * Converts markdown text to HTML using league/commonmark.
 * 
 * USAGE:
 *   $html = $this->api('markdown')->parse($markdownText);
 *   $html = $this->api('markdown')->parse($markdown, ['html_input' => 'strip']);
 * 
 * OPTIONS:
 *   - html_input: 'strip' | 'escape' | 'allow' (default: 'escape')
 *   - allow_unsafe_links: bool (default: false)
 *   - max_nesting_level: int (default: PHP_INT_MAX)
 * 
 * This API provides a stable interface regardless of the underlying
 * commonmark version. If we upgrade from v2 to v3, this API stays the same.
 */

use League\CommonMark\CommonMarkConverter;
use League\CommonMark\GithubFlavoredMarkdownConverter;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\MarkdownConverter;

return function (string $markdown, array $options = []): array {
    // Default configuration
    $config = [
        'html_input' => $options['html_input'] ?? 'escape',
        'allow_unsafe_links' => $options['allow_unsafe_links'] ?? false,
        'max_nesting_level' => $options['max_nesting_level'] ?? PHP_INT_MAX,
    ];
    
    // Check if GFM (GitHub Flavored Markdown) is requested
    $useGfm = $options['gfm'] ?? true;
    
    try {
        if ($useGfm) {
            // GFM includes tables, strikethrough, autolinks, task lists
            $converter = new GithubFlavoredMarkdownConverter($config);
        } else {
            // Standard CommonMark
            $converter = new CommonMarkConverter($config);
        }
        
        $html = $converter->convert($markdown)->getContent();
        
        return [
            'success' => true,
            'html' => $html,
            'source_length' => strlen($markdown),
            'result_length' => strlen($html),
            'gfm' => $useGfm,
        ];
    } catch (\Throwable $e) {
        return [
            'success' => false,
            'error' => $e->getMessage(),
            'html' => '',
        ];
    }
};
