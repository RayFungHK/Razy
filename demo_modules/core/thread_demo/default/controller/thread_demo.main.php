<?php
/**
 * Thread Demo - Main Page
 * 
 * @llm Demonstrates ThreadManager for async task execution.
 * Uses XHR AJAX to run demos and display results inline.
 */

use Razy\Controller;

return function (): void {
    /** @var Controller $this */
    header('Content-Type: text/html; charset=UTF-8');

    $header = $this->api('demo/demo_index')->header('Thread Demo', 'CLI worker threads with IPC messaging');
    $footer = $this->api('demo/demo_index')->footer();

    $source = $this->loadTemplate('main');
    $source->assign(['module_url' => rtrim($this->getModuleURL(), '/')]);

    echo $header;
    echo $source->output();
    echo $footer;
};
