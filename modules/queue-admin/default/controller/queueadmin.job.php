<?php
/**
 * razymod/queue-admin — job lookup feed (GET /<module-alias>/job?id=…).
 *
 * HTTP companion of the internal 'job' API command; JSON body keeps values
 * raw by design (RZ-004 boundary, same discipline as the status feed).
 */

use Razy\Controller;

return function (): void {
    /** @var Controller $this */
    // lint-allow: RZ-003 — query input cast immediately to string per the manual/03 §4 demo pattern.
    $id = (string) ($_GET['id'] ?? '');

    if ($id === '') {
        $this->xhr()->responseAsBody(['ok' => false, 'error' => 'id query parameter required']);

        return;
    }

    $resolver = require __DIR__ . '/support/store.php';
    $store = $resolver();

    if ($store === null) {
        $this->xhr()->responseAsBody(['ok' => false, 'error' => 'no database connection available (queue store unresolvable)']);

        return;
    }

    require_once __DIR__ . '/support/QueueAdminService.php';

    $service = new \Razy\Module\queueadmin\QueueAdminService($store);
    $this->xhr()->responseAsBody(['ok' => true, 'job' => $service->job($id)]);
};
