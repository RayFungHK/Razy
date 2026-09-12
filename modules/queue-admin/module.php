<?php
/**
 * razymod/queue-admin — module metadata.
 *
 * First-party queue dashboard (Wave 1 of architecture/PORTING-VALUE.md):
 * Horizon-style status/action surface over QueueStoreInterface, so it works
 * over DatabaseStore AND RedisQueueStore without store-specific code.
 *
 * Shape follows demos/golden/provider (the reference module layout).
 */

return [
    'module_code' => 'razymod/queue-admin',
    'name'        => 'Queue Admin',
    'author'      => 'Razy Framework',
    'description' => 'Queue dashboard: per-queue status counts, job lookup, release/bury/delete actions over any QueueStoreInterface — with an optional self-contained HTML shell (/ui)',
    'version'     => '1.1.0', // RZ-012: minor — new HTTP surface (/ui /job /act /purge), nothing removed
];
