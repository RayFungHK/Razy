<?php

/**
 * razymod/permissions — public API command 'can' (hot path, never throws).
 *
 * Return contract is a PLAIN bool (§8): the razit loop stays
 * `api('razymod/permissions')->can('x') !== true` — a denied-by-gate null
 * or a thrown error must never masquerade as a decision.
 */

return function (string $ability, ?string $actorId = null): bool {
    /** @var Razy\Module\permissions\PermissionController $this bound by ClosureLoader at execution */
    return $this->service()->can($ability, $actorId);
};
