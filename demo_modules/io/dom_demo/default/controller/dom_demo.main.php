<?php
/**
 * DOM Demo - Main Page
 * 
 * @llm Demonstrates Razy DOM builder for HTML generation.
 * Uses XHR AJAX to load demo results inline.
 */

use Razy\Controller;

return function (): void {
    /** @var Controller $this */
    header('Content-Type: text/html; charset=UTF-8');

    $header = $this->api('demo/demo_index')->header('DOM Builder Demo', 'Fluent HTML element builder');
    $footer = $this->api('demo/demo_index')->footer();

    $source = $this->loadTemplate('main');
    $source->assign(['module_url' => rtrim($this->getModuleURL(), '/')]);

    echo $header;
    echo $source->output();
    echo $footer;
};
