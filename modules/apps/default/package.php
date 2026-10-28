<?php

/**
 * razymod/apps — package metadata (version dir: "default").
 *
 * 'api_name' lives here (ModuleInfo.php reads $settings['api_name']); it is
 * also the library namespace segment: razymod\apps\… resolves to
 * library/… through the distributor's module autoloader.
 * No 'migration' key: this version owns no tables (data spaces and the
 * registry tables arrive with RZA-B / RZA-F).
 */

namespace Razy\Module\apps;

return [
    'name' => 'Apps',
    'version' => '0.2.0',
    'author' => 'Razy Framework',
    'description' => 'Apps as data: definition schema v1, validator, canonical hash, element ids, expressions, compiler, weight (RZA-A)',
    'api_name' => 'apps',
];
