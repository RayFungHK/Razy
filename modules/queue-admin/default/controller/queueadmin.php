<?php
/**
 * razymod/queue-admin — main controller.
 *
 * Registration-only in __onInit (RZ-009); all IO lives in route/API handlers.
 * Layout follows demos/golden/provider (the reference shape).
 */

namespace Razy\Module\queueadmin;

use Razy\Agent;
use Razy\Controller;
use Razy\ModuleInfo;

return new class extends Controller {
    /** Published surface (RZ-010); everything else stays private. */
    private const API_ALLOW = [
        'status' => true,
        'job'    => true,
        'act'    => true,
        'purge'  => true,
    ];

    public function __onInit(Agent $agent): bool
    {
        // RZ-013: routes only through Agent. 'ui' is the HTML shell,
        // 'job' its lookup feed, 'act'/'purge' guarded POST mutations
        // (POST-only + CSRF double-submit + service validation).
        $agent->addLazyRoute(['status' => 'status']);
        $agent->addLazyRoute(['ui' => 'ui']);
        $agent->addLazyRoute(['job' => 'job']);
        $agent->addLazyRoute(['act' => 'act']);
        $agent->addLazyRoute(['purge' => 'purge']);

        // Public commands (RZ-010). Paths relative to controller/, extension
        // appended by the loader (ClosureLoader.php:130) — never write '.php'.
        $agent->addAPICommand('status', 'api/status');
        $agent->addAPICommand('job', 'api/job');
        $agent->addAPICommand('act', 'api/action');
        $agent->addAPICommand('purge', 'api/purge');

        // NOTE: no bind() helper — handlers require this module's OWN support
        // files by path (controller/support/*); self-inclusion is RZ-001-clean
        // and keeps invocation semantics unambiguous.
        return true;
    }

    /**
     * Gate for api('razymod/queue-admin')->… (RZ-010). Framework default
     * allows ALL — implemented allow-list denies the unknown (AGENTS.md trap).
     * $module is the REQUESTING module's ModuleInfo (Controller.php:173).
     *
     * Note: 'act'/'purge' are mutating; tighten per-caller (e.g. only an
     * admin module) once a caller inventory exists.
     */
    public function __onAPICall(ModuleInfo $module, string $method, string $fqdn = ''): bool
    {
        return isset(self::API_ALLOW[$method]);
    }
};
