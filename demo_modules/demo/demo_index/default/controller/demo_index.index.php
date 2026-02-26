<?php
/**
 * Index Page - Demo Overview (Template Engine Version)
 * 
 * @llm Main landing page showing all registered demo modules.
 * Route: / (absolute route via addRoute)
 * Uses Razy Template Engine for rendering.
 */

use Razy\Controller;

return function (): void {
    /** @var Controller $this */
    
    header('Content-Type: text/html; charset=UTF-8');
    
    $styles = $this->getStyles();
    $demos = $this->getDemos();
    $siteUrl = $this->getSiteURL();
    
    // Group demos by category
    $categories = [
        'Core Features' => [],
        'Data Handling' => [],
        'Web & API' => [],
        'Advanced' => [],
    ];
    
    // Categorize demos
    foreach ($demos as $demo) {
        $cat = $demo['category'] ?? 'Core Features';
        if (!isset($categories[$cat])) {
            $categories[$cat] = [];
        }
        $categories[$cat][] = $demo;
    }
    
    // Compute counts
    $demoCount = count($demos);
    $categoryCount = count(array_filter($categories, fn($c) => !empty($c)));
    
    // Load template
    $source = $this->loadTemplate('index');
    $root = $source->getRoot();
    
    // Assign global variables
    $source->assign([
        'styles' => $styles,
        'site_url' => $siteUrl,
        'demo_count' => $demoCount,
        'category_count' => $categoryCount,
    ]);
    
    // Build template blocks
    if (empty($demos)) {
        // Show empty state
        $root->newBlock('empty');
    } else {
        // Create category blocks with demo children
        foreach ($categories as $categoryName => $catDemos) {
            if (empty($catDemos)) {
                continue;
            }
            
            $categoryBlock = $root->newBlock('category')->assign([
                'category_name' => $categoryName,
            ]);
            
            foreach ($catDemos as $demo) {
                $categoryBlock->newBlock('demo')->assign([
                    'demo_name' => htmlspecialchars($demo['name'] ?? 'Unnamed'),
                    'demo_description' => htmlspecialchars($demo['description'] ?? 'No description'),
                    'demo_url' => ltrim($demo['url'] ?? '/', '/'),
                    'demo_icon' => $demo['icon'] ?? '📦',
                    'demo_routes' => htmlspecialchars($demo['routes'] ?? ''),
                ]);
            }
        }
    }
    
    // Output rendered template
    echo $source->output();
};
