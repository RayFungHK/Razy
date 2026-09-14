<?php

/**
 * razymod/permissions — governance API command 'audit-actor' (S5).
 *
 * Newest-first denial history for one actor key ("type:id") — the read
 * side of the OPT-IN audit table (config `audit`). Governor-only
 * (GOVERN_ONLY allow-list). The permission.denied EVENT remains the
 * primary audit surface (§8); this answers the after-the-fact question
 * the event cannot.
 */

return function (string $actorKey, int $limit = 50): array {
    /** @var Razy\Module\permissions\PermissionController $this bound by ClosureLoader at execution */
    return $this->service()->auditActor($actorKey, $limit);
};
