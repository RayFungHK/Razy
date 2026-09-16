<?php

/**
 * razymod/oauth — package metadata (version dir: "default").
 *
 * NO 'migration' key ON PURPOSE (signed Q1): this module stores nothing —
 * no users table, no identities, no token vaults (the dossier's
 * Do-NOT-build list applies with full force here). If a future release ever
 * needs storage, it gets its own migration files and a minor bump, never a
 * runtime migrate.
 * 'provision' => 'none' states that absence at the provision door too
 * (MODULE-LIFECYCLE.md Q2): no schema, no deploy step, no wizard — nothing
 * to provision, declared.
 */

namespace Razy\Module\oauth;

return [
    'name' => 'Social Login',
    'version' => '0.1.1', // synced with module.php (XHR::responseAsBody revival)
    'author' => 'Razy Framework',
    'description' => 'Social login routes over the OAuth2 core (dossier OAUTH-SOCIALITE-HTTP.md S5)',
    'api_name' => 'oauth_api',
    'provision' => 'none',
];
