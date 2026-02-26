<?php
/**
 * SSE Demo - Main Page
 * 
 * @llm Demonstrates Razy SSE for real-time streaming.
 * Uses XHR to load code examples inline.
 */

use Razy\Controller;

return function (): void {
    /** @var Controller $this */
    header('Content-Type: text/html; charset=UTF-8');

    $header = $this->api('demo/demo_index')->header('SSE Demo', 'Server-Sent Events for real-time streaming');
    $footer = $this->api('demo/demo_index')->footer();

    $source = $this->loadTemplate('main');
    $source->assign(['module_url' => rtrim($this->getModuleURL(), '/')]);

    echo $header;
    echo $source->output();
    echo $footer;
};
