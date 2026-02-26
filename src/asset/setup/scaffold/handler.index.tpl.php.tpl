<?php
/**
 * Index Page Handler
 *
 * Route: /{alias}/ (via addLazyRoute)
 *
 * @package Razy
 * @license MIT
 */

use Razy\Controller;

return function (): void {
    /** @var Controller $this */
    header('Content-Type: text/html; charset=UTF-8');

    // Load the 'index' template from view/index.tpl
    $source = $this->loadTemplate('index');
    $source->assign([
        'title'   => '{$module_name}',
        'message' => 'Your module is working! Edit this handler and template to get started.',
    ]);

    echo $source->output();
};
