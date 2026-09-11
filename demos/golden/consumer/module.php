<?php
/**
 * golden/consumer — module metadata.
 *
 * Shows the consumer side of the golden path:
 *   • dependency DECLARED in package.php (RZ-007), never require'd (RZ-001),
 *   • data consumed via api('golden/provider')->findUser() (RZ-001),
 *   • events subscribed via listen('golden/provider:userSeen', …) (RZ-008).
 *
 * Nothing in this module names a file inside the provider — if the provider
 * moves, renames a closure file, or upgrades a version tag, this module is
 * unaffected (that is the point of module boundaries).
 */

return [
    'module_code' => 'golden/consumer',
    'name'        => 'Golden Consumer',
    'author'      => 'Razy Framework',
    'description' => 'Golden-path demo: consumes golden/provider through its published API and events only',
    'version'     => '1.0.0', // RZ-012
];
