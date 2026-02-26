<?php
/**
 * YAML Demo - Main Page
 * 
 * @llm Demonstrates Razy YAML for parsing and dumping.
 * Uses XHR AJAX to load demo results inline with sample code.
 */

use Razy\Controller;

return function (): void {
    /** @var Controller $this */
    header('Content-Type: text/html; charset=UTF-8');

    $header = $this->api('demo/demo_index')->header('YAML Demo', 'Native YAML 1.2 parsing and dumping');
    $footer = $this->api('demo/demo_index')->footer();

    $source = $this->loadTemplate('main');
    $source->assign(['module_url' => rtrim($this->getModuleURL(), '/')]);

    echo $header;
    echo $source->output();
    echo $footer;
};
