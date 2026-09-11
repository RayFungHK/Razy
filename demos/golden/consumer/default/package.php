<?php
/**
 * golden/consumer — package metadata (version dir: "default").
 *
 * Dependency DECLARED, never installed or reached into by hand (RZ-007).
 * The 'require' shape module-code => version-constraint is verified in
 * src/library/Razy/ModuleInfo.php:250-256; load-order enforcement and the
 * "run compose" hint on a missing peer are verified src/library/Razy/Module.php:143-151.
 *
 * No api_name key on purpose: this module publishes no API commands — omitting
 * it is the honest default (ModuleInfo defaults to an empty API name).
 */

namespace Razy\Module\consumer;

return [
    'name'        => 'Golden Consumer',
    'version'     => '1.0.0',
    'author'      => 'Razy Framework',
    'description' => 'Golden-path demo: consumes golden/provider API + events',
    'require'     => [
        'golden/provider' => '>=1.0.0',
    ],
];
