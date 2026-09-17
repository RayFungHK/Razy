<?php

use Razy\Agent;
use Razy\Controller;

/**
 * gate-ready — route registration (benchmark gate site).
 */

return new class () extends Controller {
    public function __onInit(Agent $agent): bool
    {
        // The module-wide readiness door (MODULE-LIFECYCLE.md L3).
        $agent->readyRoutes('self')
            ->addLazyRoute('ping', 'ping');
        return true;
    }
};
