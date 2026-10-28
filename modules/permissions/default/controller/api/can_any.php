<?php

/**
 * razymod/permissions — public API command 'canAny' (mirror Gate::any).
 */

return function (array $abilities, ?string $actorId = null): bool {
    /** @var Razy\Module\permissions\PermissionController $this bound by ClosureLoader at execution */
    return $this->service()->canAny($abilities, $actorId);
};
