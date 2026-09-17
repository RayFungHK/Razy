<?php

use Razy\Agent;
use Razy\Controller;

/**
 * plain-plain — route registration (benchmark gate site).
 */

return new class () extends Controller {
    public function __onInit(Agent $agent): bool
    {
        // Ungated control: identical handler, no readiness predicate.
        $agent->addLazyRoute('ping', 'ping');
        return true;
    }
};
