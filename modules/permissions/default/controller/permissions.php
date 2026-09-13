<?php

/**
 * razymod/permissions — main controller (S2: skeleton + gates).
 *
 * The two-tier API allow-list is LIVE now (Q4 decision); the command
 * registrations land in S3 TOGETHER WITH their handlers — RZ-014 forbids a
 * command constant outrunning shipped, tested code, so __onInit registers
 * NOTHING yet and __onAPICall guards a surface that does not exist until S3.
 *
 * Config keys consumed (per-distributor, config/<dist>/permissions.php —
 * Module.php:1052-1056; keys NOT read before their milestone are listed in
 * the dossier, not here):
 *   - 'governor'  : module_code of the single module allowed to mutate roles
 *                  (Q4). Absent = no governor = every governance command
 *                  denied. Never env-extendable (config is the allow-list of
 *                  record).
 *   - 'database'  : {type, connection} — config-connect DB (§4.2 option 1,
 *                  resolved by controller/support/database.php). NEVER the
 *                  ambient-phantom pattern (§4.4 lesson).
 *
 * Shape follows razymod/queue-admin (the first-party module reference).
 */

namespace Razy\Module\permissions;

use Razy\Agent;
use Razy\Controller;
use Razy\ModuleInfo;

return new class() extends Controller {
    /**
     * Read/check commands: any module in the distributor may call them
     * (the razit loop consumer pattern — fail-closed happens at the
     * *decision*, not at the door). S3 will register: can, can-any,
     * abilities, define-ability.
     */
    private const API_ALLOW = [
        'can' => true,
        'can-any' => true,
        'abilities' => true,
        'define-ability' => true,
    ];

    /**
     * Governance commands: ONLY the config-named governor module (Q4).
     * S3 will register: roles-of, assign-role, revoke-role. S4 candidate:
     * audit-actor (kept OUT of the list until it ships).
     */
    private const GOVERN_ONLY = [
        'roles-of' => true,
        'assign-role' => true,
        'revoke-role' => true,
    ];

    public function __onInit(Agent $agent): bool
    {
        // S2 ships no routes and no API commands: registration arrives with
        // handlers in S3 (RZ-014). The gate below is pre-wired and pinned by
        // tests/PermissionsModuleTest.php so S3 can only ever plug into a
        // reviewed trust shape.
        return true;
    }

    /**
     * Gate for api('razymod/permissions')->… (RZ-010). Framework default
     * allows ALL — implemented allow-list denies the unknown (AGENTS.md trap).
     * $module is the REQUESTING module's ModuleInfo (Controller.php:173).
     */
    public function __onAPICall(ModuleInfo $module, string $method, string $fqdn = ''): bool
    {
        if (isset(self::API_ALLOW[$method])) {
            return true;
        }

        if (isset(self::GOVERN_ONLY[$method])) {
            // Absent/empty governor config must NEVER match: a bare null
            // comparison against the caller code would let a module literally
            // named '' through. Read API is ArrayAccess — Configuration
            // extends Collection which extends ArrayObject (Collection.php:31);
            // there is NO ->get() method despite manual/04:50 advertising one
            // (ledger P8 — the same phantom class as getSharedInstance).
            $governor = $this->getModuleConfig()['governor'] ?? null;

            return \is_string($governor) && $governor !== '' && $module->getCode() === $governor;
        }

        return false;
    }
};
