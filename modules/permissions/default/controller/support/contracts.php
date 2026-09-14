<?php

/**
 * razymod/permissions — ORM Contract mirrors for the four RBAC tables.
 *
 * "Migrations own the tables, Contract declares the shape" (§4.1: one
 * Column grammar, two consumers). These Contract value objects are the
 * shape the S3 handlers (and any consuming Model) read; tests pin that the
 * live sqlite schema after migration matches every field name — drift fails
 * the suite, not a code review.
 *
 * Contract::define is fail-loud at DEFINE time (Contract.php:68-70), so
 * merely loading this file validates the grammar.
 *
 * Honest limits: indexes() documents intent (relations stay undeclared
 * because these are join tables — relations are declared, not inferred,
 * Contract.php:23-26); define() enforces primary_key ∈ fields, so the joins
 * label their composite head (see per-table comment); actor_id carries NO
 * reference() flag on purpose: the users table is app-owned (Q1), a DDL FK
 * here would invent that bond.
 */

use Razy\ORM\Contract;

return [
    'permissions' => Contract::define([
        'table' => 'permissions',
        'fields' => [
            'id' => 'type(int),auto',
            'code' => 'type(text)',
            'label' => 'type(text),nullable',
            'module_code' => 'type(text)',
            'created_at' => 'type(timestamp),nullable',
        ],
        'indexes' => [
            ['columns' => ['code'], 'name' => 'uq_permissions_code'],
            ['columns' => ['module_code'], 'name' => 'idx_permissions_module'],
        ],
    ]),

    'roles' => Contract::define([
        'table' => 'roles',
        'fields' => [
            'id' => 'type(int),auto',
            'code' => 'type(text)',
            'label' => 'type(text),nullable',
            'is_system' => 'type(int)',
        ],
        'indexes' => [
            ['columns' => ['code'], 'name' => 'uq_roles_code'],
        ],
    ]),

    // Join tables carry composite UNIQUEs, no surrogate id — Contract
    // enforces primary_key ∈ fields (fail-loud define), so the label names
    // the first composite component; nothing reads it as a real PK here.
    'permission_role' => Contract::define([
        'table' => 'permission_role',
        'primary_key' => 'role_id',
        'fields' => [
            'role_id' => 'type(int),reference(roles,id)',
            'permission_id' => 'type(int),reference(permissions,id)',
        ],
        'indexes' => [
            ['columns' => ['role_id', 'permission_id'], 'name' => 'uq_permission_role'],
        ],
    ]),

    'actor_role' => Contract::define([
        'table' => 'actor_role',
        'primary_key' => 'actor_id',
        'fields' => [
            // actor_type + actor_id: TEXT pair, deliberately NOT reference()d
            // — the identity table, wherever it lives, is app-owned (Q1).
            'actor_type' => 'type(text)',
            'actor_id' => 'type(text)',
            'role_id' => 'type(int),reference(roles,id)',
        ],
        'indexes' => [
            ['columns' => ['actor_type', 'actor_id', 'role_id'], 'name' => 'uq_actor_role'],
            ['columns' => ['actor_type', 'actor_id'], 'name' => 'idx_actor'],
        ],
    ]),

    // S5 opt-in denial audit log: rows appear only when the `audit` config
    // is on; the permission.denied EVENT remains the primary surface (§8).
    'permission_audit_log' => Contract::define([
        'table' => 'permission_audit_log',
        'fields' => [
            'id' => 'type(int),auto',
            'actor_key' => 'type(text)',
            'ability' => 'type(text)',
            'source' => 'type(text)',
            'created_at' => 'type(timestamp),nullable',
        ],
        'indexes' => [
            ['columns' => ['actor_key', 'id'], 'name' => 'idx_audit_actor'],
        ],
    ]),
];
