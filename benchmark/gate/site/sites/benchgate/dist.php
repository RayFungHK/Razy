<?php

/**
 * benchgate — the distributor hosting the readiness-gate measurement modules.
 * greedy: every module in the tree loads (that IS the measurement).
 */

return [
    'dist' => 'benchgate',
    'global_module' => false,
    'autoload_shared' => false,
    'greedy' => true,
    'strict' => false,
    // COMPILE-ON-DEPLOY: replay the deploy snapshot (php Razy.phar compile
    // benchgate). Stale/missing artifact auto-falls back to the full boot.
    'compiled_boot' => true,
];
