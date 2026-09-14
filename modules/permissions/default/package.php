<?php

/**
 * razymod/permissions — package metadata (version dir: "default").
 *
 * 'api_name' lives here (ModuleInfo.php:238 reads $settings['api_name']).
 * 'migration' => 'deploy' (M3): this module's schema moves with the deploy
 * pipeline — `php Razy.phar migrate <dist>` applies its pending migrations;
 * the module code itself never migrates at runtime (web never migrates).
 */

namespace Razy\Module\permissions;

return [
    'name' => 'Permissions',
    'version' => '0.2.0',
    'author' => 'Razy Framework',
    'description' => 'RBAC policy layer over the app-provided database (dossier PERMISSION-MODULE.md S2)',
    'api_name' => 'permissions_api',
    'migration' => 'deploy',
];
