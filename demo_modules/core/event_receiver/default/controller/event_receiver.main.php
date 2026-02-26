<?php
/**
 * Main handler - Event Receiver Overview
 * 
 * @llm Displays information about events this module is listening to.
 */

use Razy\Controller;

return function (): void {
    /** @var Controller $this */
    header('Content-Type: text/html; charset=UTF-8');

    $header = $this->api('demo/demo_index')->header('Event Receiver Demo', 'Cross-module event listening');
    $footer = $this->api('demo/demo_index')->footer();

    $source = $this->loadTemplate('main');

    echo $header;
    echo $source->output();
    echo $footer;
};
