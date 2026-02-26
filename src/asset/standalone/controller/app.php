<?php
/**
 * Standalone Application Controller
 *
 * This is the main controller for a Razy standalone (lite) application.
 * In standalone mode, this is the ONLY module — no package manager,
 * no distributor config, no domain restrictions.
 *
 * Standalone mode activates automatically when:
 * 1. A standalone/ folder exists at the project root
 * 2. No sites.inc.php file is present (multisite not configured)
 *
 * To switch to multisite mode, create a sites.inc.php file.
 *
 * @package Razy
 * @license MIT
 */

use Razy\Agent;
use Razy\Controller;

return new class extends Controller {
    /**
     * Initialize routes for the standalone application.
     *
     * @param Agent $agent The module agent for route/event registration
     * @return bool Return true to indicate successful initialization
     */
    public function __onInit(Agent $agent): bool
    {
        // Register routes using lazy routing (relative to module alias)
        // '/' maps to the 'index' handler (controller/app.index.php)
        $agent->addLazyRoute([
            '/' => 'index',
        ]);

        return true;
    }
};
