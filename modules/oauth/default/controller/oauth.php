<?php

/**
 * razymod/oauth — main controller.
 *
 * Registration-only in __onInit (RZ-009); all IO lives in the two route
 * handlers. Shape follows razymod/queue-admin.
 */

namespace Razy\Module\oauth;

use Razy\Agent;
use Razy\Controller;
use Razy\ModuleInfo;

return new class() extends Controller {
    /** Published surface (RZ-010); read-only by construction — this module
     *  mutates nothing (Q1). */
    private const API_ALLOW = [
        'providers' => true,
    ];

    public function __onInit(Agent $agent): bool
    {
        // RZ-013: routes only through Agent. '/authorize' starts a login
        // (302 to the provider), '/callback' completes it (state verified
        // before any network, then social.user_resolved). Both read the
        // provider slug from the query (sanctioned queue-admin.act.php
        // pattern).
        $agent->addLazyRoute(['authorize' => 'authorize']);
        $agent->addLazyRoute(['callback' => 'callback']);

        // Public commands (RZ-010): which providers this distributor enabled
        // and their public login URLs — no secrets in either answer.
        $agent->addAPICommand('providers', 'api/providers');

        return true;
    }

    /**
     * Gate for api('razymod/oauth')->… (RZ-010). Framework default allows
     * ALL — implemented allow-list denies the unknown (AGENTS.md trap,
     * queue-admin shape). Only one published, read-only command exists.
     */
    public function __onAPICall(ModuleInfo $module, string $method, string $fqdn = ''): bool
    {
        return isset(self::API_ALLOW[$method]);
    }
};
