<?php
/**
 * SimplifiedMessage Demo - Main Page
 * 
 * @llm Demonstrates STOMP-like message protocol with styled HTML page.
 * Loads demo results via XHR AJAX.
 */

use Razy\Controller;

return function (): void {
    /** @var Controller $this */
    header('Content-Type: text/html; charset=UTF-8');

    $header = $this->api('demo/demo_index')->header('Message Demo', 'STOMP-like message protocol implementation');
    $footer = $this->api('demo/demo_index')->footer();

    $source = $this->loadTemplate('main');
    $source->assign(['module_url' => rtrim($this->getModuleURL(), '/')]);

    echo $header;
    echo $source->output();
    echo $footer;
};
