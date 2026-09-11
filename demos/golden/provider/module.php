<?php
/**
 * golden/provider — module metadata.
 *
 * Golden-path demo: this module is the REFERENCE for what a Razy module must
 * look like. Every API call used here was verified against src/library/Razy/
 * (file:line citations inline). AI agents: copy this shape, not the legacy
 * demo_modules/ (which currently trip lint: 15 superglobal reads + 97
 * raw-output template warnings via tools/lint-module-discipline.php).
 *
 * Shape verified against demo_modules/io/api_provider/module.php.
 */

return [
    'module_code' => 'golden/provider',
    'name'        => 'Golden Provider',
    'author'      => 'Razy Framework',
    'description' => 'Golden-path demo: publishes findUser() via addAPICommand and gates it with __onAPICall (RZ-010)',
    'version'     => '1.0.0', // RZ-012: bump on ANY released-surface change (API/event/route)
];
