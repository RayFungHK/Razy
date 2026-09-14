<?php
/**
 * golden/policy — package metadata (version dir: "default").
 *
 * Dependency DECLARED (RZ-007), never reached into (RZ-001). The version
 * constraint mirrors razymod/permissions' package.php semver surface; in a
 * real distributor `php Razy.phar compose` resolves the peer before this
 * module's routes serve traffic.
 */

namespace Razy\Module\policy;

return [
    'name'        => 'Golden Policy',
    'version'     => '1.0.0',
    'author'      => 'Razy Framework',
    'description' => 'Golden-path demo: permission checks through razymod/permissions public API',
    'require'     => [
        'razymod/permissions' => '>=0.1.0',
    ],
];
