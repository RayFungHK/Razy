<?php
/**
 * golden/policy — module metadata.
 *
 * The permission-CONSUMER golden path (razymod/permissions, dossier
 * PERMISSION-MODULE.md; manual/11):
 *   • the check goes through the published API only (RZ-001):
 *     api('razymod/permissions')->can('golden.publish') — never a method
 *     call on the permission module's controller, never its files,
 *   • api() is NULLABLE and an ungranted check returns false-or-null, so
 *     the consumer compares `!== true` (fail-closed twice over),
 *   • templates gate blocks with the module's {can} plugin ({@can …}),
 *     server-side: denied markup never ships.
 */

return [
    'module_code' => 'golden/policy',
    'name'        => 'Golden Policy',
    'author'      => 'Razy Framework',
    'description' => 'Golden-path demo: checks permissions through razymod/permissions public API, fail-closed',
    'version'     => '1.0.0', // RZ-012
];
