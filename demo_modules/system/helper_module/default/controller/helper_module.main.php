<?php
/**
 * Helper Module - Main Page
 * 
 * @llm Companion module for advanced features demo.
 */

use Razy\Controller;

return function (): void {
    /** @var Controller $this */
    header('Content-Type: text/html; charset=UTF-8');

    $header = $this->api('demo/demo_index')->header('Helper Module', 'Companion module for await() and addShadowRoute()');
    $footer = $this->api('demo/demo_index')->footer();

    $source = $this->loadTemplate('main');

    echo $header;
    echo $source->output();
    echo $footer;
};
