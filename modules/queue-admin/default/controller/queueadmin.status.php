<?php
/**
 * razymod/queue-admin — lazy-route handler for /<module-alias>/status.
 *
 * JSON status feed for the (next-iteration) HTML shell. Query parameters come
 * via getRoutedInfo(), never superglobals (RZ-003). Output is a JSON body on
 * purpose — raw values are correct for JSON clients (RZ-004 boundary note,
 * same discipline as golden/consumer.panel.php).
 */

use Razy\Controller;

return function (): void {
    /** @var Controller $this */
    $resolver = require __DIR__ . '/support/store.php';
    $store = $resolver();

    if ($store === null) {
        $this->xhr()->responseAsBody([
            'ok'    => false,
            'error' => 'no database connection available (queue store unresolvable)',
        ]);

        return;
    }

    require_once __DIR__ . '/support/QueueAdminService.php';

    $routed = $this->getRoutedInfo();
    $arguments = is_array($routed['arguments'] ?? null) ? $routed['arguments'] : [];
    $queues = array_values(array_filter(array_map('strval', (array) ($arguments['queues'] ?? ['default']))));

    $service = new \Razy\Module\queueadmin\QueueAdminService($store);
    $this->xhr()->responseAsBody(['ok' => true, 'status' => $service->status($queues)]);
};
