<?php

/**
 * razymod/apps — module metadata.
 *
 * Razy Apps (RZA-A): the definition engine for apps described as data.
 * A definition (entities, fields, relations, views, workflows, permissions,
 * i18n) is validated against schema v1 with caps, hashed over canonical
 * JSON, given stable element ids, and compiled into a runtime plan with
 * every expression parsed and typed. Expressions use a small Excel-like
 * language with a closed function list, run by an interpreter with a step
 * budget (no eval, no PHP callbacks).
 *
 * Generic by design: no customer, product or author-specific concepts. Data spaces,
 * physical tables and the record runtime come in later modules / versions
 * (RZA-B apps-schema, RZA-C record runtime). Shape follows
 * razymod/permissions (modules/permissions).
 */

return [
    'module_code' => 'razymod/apps',
    'name' => 'Apps',
    'author' => 'Razy Framework',
    'owner' => 'Razy Framework', // docs/STYLE.md §0: decides changes to this module and its API.md
    'description' => 'Apps as data: definition schema v1, validator with caps, canonical JSON + content hash, stable element ids, Excel-like expressions (typed AST, budgeted evaluator), compiler and weight function',
    'version' => '0.2.0', // RZ-012 minor (0.x): RZ-48 renamed assign-ids to assignIds, the only name the Agent accepts
];
