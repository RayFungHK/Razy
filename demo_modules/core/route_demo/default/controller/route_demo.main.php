<?php
/**
 * Main handler - Route Demo Overview
 * 
 * @llm Displays available route patterns with inline XHR test.
 */

use Razy\Controller;

return function (): void {
    /** @var Controller $this */
    header('Content-Type: text/html; charset=UTF-8');

    $header = $this->api('demo/demo_index')->header('Route Demo', 'Dynamic URL routing and lazy route registration');
    $footer = $this->api('demo/demo_index')->footer();

    $source = $this->loadTemplate('main');

    echo $header;
    echo $source->output();
    echo $footer;
};
