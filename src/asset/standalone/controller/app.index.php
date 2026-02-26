<?php
/**
 * Index Route Handler — Standalone Application
 *
 * Handles the root URL (/) for the standalone application.
 * This file is loaded when a request matches the '/' lazy route
 * defined in the main controller (app.php).
 *
 * @package Razy
 * @license MIT
 */

use Razy\Controller;

return function (): void {
    /** @var Controller $this */
    header('Content-Type: text/html; charset=UTF-8');

    // Load and render the index template
    $template = $this->loadTemplate('index');
    echo $template->output();
};
