<?php
/**
 * golden/policy — main controller.
 *
 * Registers ONE gated route; the handler (policy.panel.php) answers the
 * permission question through the sanctioned API only. No events observed
 * here on purpose: `permission.denied` is the AUDITOR's surface (subscribe
 * only if you audit), not the consumer's.
 */

namespace Razy\Module\policy;

use Razy\Agent;
use Razy\Controller;

return new class extends Controller {
    public function __onInit(Agent $agent): bool
    {
        // RZ-013: routes through Agent only. Slash-less path is prefixed
        // with the module class name by ClosureLoader (policy.panel.php).
        $agent->addLazyRoute(['panel' => 'panel']);

        return true; // RZ-009
    }
};
