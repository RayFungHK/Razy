<?php
/**
 * golden/consumer — main controller.
 *
 * Cross-module touchpoints are ONLY the sanctioned ones (AGENTS.md prime
 * directive #2): the provider's published API (see consumer.panel.php) and
 * the provider's events (registered below). Nothing else crosses the boundary.
 */

namespace Razy\Module\consumer;

use Razy\Agent;
use Razy\Controller;

return new class extends Controller {
    public function __onInit(Agent $agent): bool
    {
        // RZ-013: routes through Agent only.
        $agent->addLazyRoute(['panel' => 'panel']);

        // RZ-008: broadcast = events, never shared state. listen() takes the
        // EMITTER-QUALIFIED name 'vendor/module:event_name' (verified
        // src/library/Razy/Agent.php:152-166 and the format validation at
        // src/library/Razy/Agent.php:177-181). The provider fires a BARE
        // trigger('userSeen'); EventEmitter qualifies it with the emitter's
        // module code (verified src/library/Razy/EventEmitter.php:72-73) —
        // so 'golden/provider:userSeen' is exactly what fires.
        //
        // Registration here is inert: the closure runs later, when the
        // provider resolves the event (RZ-009: __onInit registers, never acts).
        // Payload arrives as the first argument (verified
        // src/library/Razy/Module/EventDispatcher.php:139 call_user_func_array).
        $agent->listen('golden/provider:userSeen', function (array $payload = []): array {
            return [
                'heard_by' => 'golden/consumer',
                'user_id'  => $payload['user_id'] ?? null,
                'at'       => date('c'),
            ];
        });

        return true; // RZ-009: lifecycle must return a meaningful true
    }
};
