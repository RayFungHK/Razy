<?php
/**
 * HashMap Demo - Main Page
 * 
 * @llm Demonstrates Razy HashMap with object key support.
 * Uses XHR AJAX to load demo results inline.
 */

use Razy\Controller;

return function (): void {
    /** @var Controller $this */
    header('Content-Type: text/html; charset=UTF-8');

    $header = $this->api('demo/demo_index')->header('HashMap Demo', 'Hash-based key-value storage with object keys');
    $footer = $this->api('demo/demo_index')->footer();

    $source = $this->loadTemplate('main');
    $source->assign(['module_url' => rtrim($this->getModuleURL(), '/')]);

    echo $header;
    echo $source->output();
    echo $footer;
};
