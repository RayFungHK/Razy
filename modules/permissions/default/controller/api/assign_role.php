<?php

/**
 * razymod/permissions — governance command 'assign-role' (governor-only, Q4).
 *
 * Unknown role codes are REFUSED, never auto-created: role vocabulary is
 * governed data, invented only by explicit governance action.
 */

return function (string $actorType, string $actorId, string $roleCode): array {
    /** @var Razy\Module\permissions\PermissionController $this bound by ClosureLoader at execution */
    return $this->service()->assignRole($actorType, $actorId, $roleCode);
};
