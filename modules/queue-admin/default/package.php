<?php

/**
 * razymod/queue-admin — package metadata (version dir: "default").
 *
 * 'api_name' lives here (ModuleInfo.php:238 reads $settings['api_name']).
 * 'provision' => 'none' (MODULE-LIFECYCLE.md Q2): this module ships no
 * schema of its own (the queue store belongs to its host) — declaring the
 * absence honestly beats leaving the reader guessing.
 */

namespace Razy\Module\queueadmin;

return [
    'name' => 'Queue Admin',
    'version' => '1.1.0',
    'author' => 'Razy Framework',
    'description' => 'Queue dashboard over QueueStoreInterface with an optional HTML shell (/ui)',
    'api_name' => 'queue_admin_api',
    'provision' => 'none',
];
