<?php
/**
 * golden/provider — main controller.
 *
 * File/anonymous-class shape mirrors demo_modules/io/api_provider/default/
 * controller/api_provider.php on disk (verified 2026-07). The framework loads
 * controller/<class-name>.php and requires the file to RETURN an anonymous
 * class extending Razy\Controller (verified src/library/Razy/Module.php:159,188).
 *
 * __onInit is registration-only — RZ-009: no IO, no peer calls, no firing
 * events here (peers are not loaded yet, listeners are not all registered).
 * Request-time actions live in route handlers (see provider.users.php).
 *
 * Rule IDs reference skills/RAZY-AI-RULES.md (rule pack quick ref, Appendix D).
 */

namespace Razy\Module\provider;

use Razy\Agent;
use Razy\Controller;
use Razy\ModuleInfo;

return new class extends Controller {
    /**
     * Allow-list for the published API surface (RZ-010).
     *
     * A published command is a versioned contract (RZ-012) — add only what
     * peers genuinely need; internals stay behind $agent->bind() below.
     */
    private const API_ALLOW = [
        'findUser' => true,
    ];

    public function __onInit(Agent $agent): bool
    {
        // RZ-013: routes go through Agent only; generated rewrite files stay
        // machine-owned. Lazy routes are relative to the module alias and
        // prefix-match; positional capture belongs in addRoute('…(:d)', …).
        $agent->addLazyRoute(['users' => 'users']);

        // RZ-010: addAPICommand() = PUBLIC to every module in this distributor.
        // The path is relative to controller/ and the loader appends '.php'
        // itself (verified src/library/Razy/Module/ClosureLoader.php:130) —
        // register WITHOUT the extension: 'api/find_user.php' would resolve to
        // controller/api/find_user.php.php.
        $agent->addAPICommand('findUser', 'api/find_user');

        // RZ-010: the internal counterpart on the SAME closure. bind() resolves
        // only through $this->… inside this module (verified
        // src/library/Razy/Agent.php:77-86). This module's own route handler
        // calls lookupUser(); peers call findUser() via api(). One file, two
        // names — public API vs private helper made explicit.
        $agent->bind('lookupUser', 'api/find_user');

        return true; // RZ-009: a meaningful true keeps the lifecycle alive
    }

    /**
     * Security gate for api('golden/provider')->… dispatches (RZ-010).
     *
     * Signature verified in CODE (not the rules-pack Appendix D sample, which
     * is stale): src/library/Razy/Controller.php:173 + call site
     * src/library/Razy/Module/CommandRegistry.php:121-129 — $module is the
     * REQUESTING module's ModuleInfo. The framework default allows everything;
     * "no gate means open" is a documented trap (AGENTS.md) — always implement.
     */
    public function __onAPICall(ModuleInfo $module, string $method, string $fqdn = ''): bool
    {
        // Unknown command → deny. Narrow further per caller when needed:
        // $caller = $module->getCode();                       // e.g. 'golden/consumer'
        // return isset(self::API_ALLOW[$method]) && $caller === 'golden/consumer';
        return isset(self::API_ALLOW[$method]);
    }
};
