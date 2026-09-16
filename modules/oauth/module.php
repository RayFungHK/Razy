<?php

/**
 * razymod/oauth — module metadata.
 *
 * Social login for Razy (architecture/OAUTH-SOCIALITE-HTTP.md, Q1-Q5 all
 * signed 2026-09-17): the framework owns the PROTOCOL (Razy\Security\OAuth\*,
 * S2/S3), this module owns the ROUTES and the per-distributor CONFIG — and,
 * per signed Q1, NOTHING else. No users table, no session writing: the
 * callback ends in `social.user_resolved` and the app's own listener decides
 * identity persistence (SessionGuard territory).
 *
 * Secrets discipline (signed Q5): config files carry ENV VAR NAMES
 * (`client_id_env` / `client_secret_env`), never values — same rule as
 * RAZY_BRIDGE_SECRET / RAZY_REGISTRY_*.
 *
 * Shape follows razymod/queue-admin + razymod/permissions (modules/).
 */

return [
    'module_code' => 'razymod/oauth',
    'name' => 'Social Login',
    'author' => 'Razy Framework',
    'description' => 'OAuth2 social login routes (GitHub/Google/Microsoft Entra) on the Razy\Security\OAuth core: PKCE S256, signed single-use state, secrets from env, identity handed to the app via social.user_resolved — no users table (Q1)',
    'version' => '0.1.1', // RZ-012: patch — error-route JSON bodies finally emit (core XHR::responseAsBody revival); route layer unchanged. Was 0.1.0 (S5 first ship: protocol surface is the S2/S3 core's)
];
