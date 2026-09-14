<?php

/**
 * razymod/permissions — governance command 'revoke-role' (governor-only, Q4).
 * Idempotent: revoking an absent grant is a no-op success.
 */

return function (string $actorType, string $actorId, string $roleCode): array {
    /** @var Razy\Module\permissions\PermissionController $this bound by ClosureLoader at execution */
    return $this->service()->revokeRole($actorType, $actorId, $roleCode);
};
