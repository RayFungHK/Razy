<?php

/**
 * razymod/permissions — package metadata (version dir: "default").
 *
 * 'api_name' lives here (ModuleInfo.php:238 reads $settings['api_name']).
 * 'migration' => 'deploy' (M3): this module's schema moves with the deploy
 * pipeline — `php Razy.phar migrate <dist>` applies its pending migrations;
 * the module code itself never migrates at runtime (web never migrates).
 * 'provision' => 'wizard' (MODULE-LIFECYCLE.md Q2): permissions is the
 * login foundation — the classic bootstrap chicken-and-egg (its tables may
 * be what the login system itself needs). The framework wizard runner is
 * the sanctioned first-run door for exactly this shape: operator mints a
 * one-time token (`module wizard-token`), the runner spends it. When this
 * module gains HTTP routes, register them behind `$agent->readyRoutes('self')`
 * so they answer the 302-until-ready instead of faulting on missing tables.
 */

namespace Razy\Module\permissions;

return [
    'name' => 'Permissions',
    'version' => '0.2.0',
    'author' => 'Razy Framework',
    'description' => 'RBAC policy layer over the app-provided database (dossier PERMISSION-MODULE.md S2)',
    'api_name' => 'permissions_api',
    'migration' => 'deploy',
    'provision' => 'wizard',
];
