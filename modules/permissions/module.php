<?php

/**
 * razymod/permissions — module metadata.
 *
 * Permission layer for Razy (architecture/PERMISSION-MODULE.md, hybrid option
 * (iii)): the framework owns the SEAM (who am I — Razy\Auth\*), this module
 * owns the POLICY (what may I) — four RBAC tables in the app-provided DB,
 * a two-tier API allow-list, and (S3+) Gate registration answering abilities
 * from the DB.
 *
 * Maintainer decisions baked in: Q1 user row never lives here (actor_id is a
 * text reference ONLY); Q4 mutations governed by one config-named governor
 * module, no HTTP mutation surface; Q5 direct grants (actor_permission) are
 * schema-free and code-free until a named use case.
 *
 * S2 = skeleton + schema + gates only. The check surface (can/assign/…)
 * registers WITH its handlers in S3 (RZ-014: no command ships before code).
 *
 * Shape follows razymod/queue-admin (modules/queue-admin).
 */

return [
    'module_code' => 'razymod/permissions',
    'name' => 'Permissions',
    'author' => 'Razy Framework',
    'description' => 'RBAC policy layer: permissions/roles tables in the app-provided database, ability catalog answering Razy\Auth\Gate, config-named governor for mutations, permission.denied audit event',
    'version' => '0.1.0', // S2 skeleton; first published surface (can/…) lands 0.2.0 with S3
];
