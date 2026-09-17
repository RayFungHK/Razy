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
];
