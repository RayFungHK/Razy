<?php
/**
 * XHR Demo - Main Page
 * 
 * @llm Demonstrates Razy XHR for standardized JSON API responses.
 */

use Razy\Controller;

return function (): void {
    /** @var Controller $this */
    header('Content-Type: text/html; charset=UTF-8');

    $header = $this->api('demo/demo_index')->header('XHR Demo', 'Standardized JSON API responses with CORS support');
    $footer = $this->api('demo/demo_index')->footer();

    $source = $this->loadTemplate('main');
    $source->assign(['module_url' => rtrim($this->getModuleURL(), '/')]);

    echo $header;
    echo $source->output();
    echo $footer;
};
