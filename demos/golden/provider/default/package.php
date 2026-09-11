<?php
/**
 * golden/provider — package metadata (version dir: "default").
 *
 * 'api_name' is read from THIS file — verified src/library/Razy/ModuleInfo.php:238
 * ($settings['api_name']). Legacy docs placing it elsewhere are stale; code wins
 * (AGENTS.md prime directive #1).
 *
 * Version discipline (RZ-012): keep in sync with ../module.php; a breaking API
 * change ships as a new version directory, never mutates a released one.
 */

namespace Razy\Module\provider;

return [
    'name'        => 'Golden Provider',
    'version'     => '1.0.0',
    'author'      => 'Razy Framework',
    'description' => 'Golden-path demo: API provider module',
    'api_name'    => 'provider_api',
];
