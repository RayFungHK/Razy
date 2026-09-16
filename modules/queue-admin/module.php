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
    'name' => 'Queue Admin',
    'author' => 'Razy Framework',
    'description' => 'Queue dashboard: per-queue status counts, job lookup, release/bury/delete actions over any QueueStoreInterface — with an optional self-contained HTML shell (/ui)',
    'version' => '1.2.1', // RZ-012: patch — HTTP JSON answers finally emit (the core XHR::responseAsBody fix revived this module's silently-empty bodies); the field was desynced from package.php (1.2.0/1.1.0, an L4 slip) — aligned here; prior 1.2.0: CSRF transport moved onto the dist's armed door (CSRF-RAIL.md L4), nothing removed
];
