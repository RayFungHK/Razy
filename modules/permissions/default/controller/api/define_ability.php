<?php

/**
 * razymod/permissions — public API command 'define-ability' (idempotent
 * catalog insert; modules call it from their own __onReady after handshake,
 * §8). module_code is optional advisory metadata; the DECISION never reads
 * it (answers come from the actor-role-permission join).
 */

return function (string $code, string $label = '', string $moduleCode = 'razymod/permissions'): array {
    /** @var Razy\Module\permissions\PermissionController $this bound by ClosureLoader at execution */
    return $this->service()->defineAbility($code, $label, $moduleCode);
};
