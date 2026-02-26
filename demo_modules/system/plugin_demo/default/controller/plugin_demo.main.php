<?php
/**
 * Plugin Demo - Main Page
 * 
 * @llm Demonstrates Razy's modular plugin system.
 * Shows sample code and loads demo results via XHR AJAX.
 */

use Razy\Controller;

return function (): void {
    /** @var Controller $this */
    header('Content-Type: text/html; charset=UTF-8');

    $header = $this->api('demo/demo_index')->header('Plugin Demo', 'Modular plugin system for extensions');
    $footer = $this->api('demo/demo_index')->footer();

    $source = $this->loadTemplate('main');
    $source->assign(['module_url' => rtrim($this->getModuleURL(), '/')]);

    echo $header;
    echo $source->output();
    echo $footer;
};
