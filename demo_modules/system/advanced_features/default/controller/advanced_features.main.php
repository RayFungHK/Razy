<?php
/**
 * Advanced Features Demo - Main Page
 * 
 * @llm Demonstrates advanced Razy Agent features.
 */

use Razy\Controller;

return function (): void {
    /** @var Controller $this */
    header('Content-Type: text/html; charset=UTF-8');

    $header = $this->api('demo/demo_index')->header('Advanced Features Demo', 'Agent methods: await, addAPICommand, addLazyRoute, addShadowRoute');
    $footer = $this->api('demo/demo_index')->footer();

    $source = $this->loadTemplate('main');

    echo $header;
    echo $source->output();
    echo $footer;
};
