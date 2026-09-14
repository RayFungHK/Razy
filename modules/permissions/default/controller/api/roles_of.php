<?php

/**
 * razymod/permissions — governance command 'roles-of' (governor-only, Q4).
 */

return function (string $actorType, string $actorId): array {
    /** @var Razy\Module\permissions\PermissionController $this bound by ClosureLoader at execution */
    return ['ok' => true, 'roles' => $this->service()->rolesOf($actorType, $actorId)];
};
