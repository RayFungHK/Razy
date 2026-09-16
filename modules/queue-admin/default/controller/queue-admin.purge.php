<?php

/**
 * razymod/queue-admin — guarded purge route (POST /<module-alias>/purge).
 *
 * Same guard chain as /act: POST-only, the armed CSRF door (419), then
 * service semantics (clear finished/buried jobs of ONE queue — queue name
 * is required, no implicit-all on purpose).
 */

use Razy\Controller;

return function (): void {
    /** @var Controller $this */
    // lint-allow: RZ-003 — method gate for this POST-only endpoint, compared to a constant.
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        $this->xhr()->responseCode(405)->responseAsBody(['ok' => false, 'error' => 'POST required']);

        return;
    }

    // CSRF is the armed dist's door now (CSRF-RAIL.md L4) — same guard
    // story as /act: validated-or-never-here, and an UNARMED dist throws at
    // csrfToken() instead of running purge unprotected.
    $this->csrfToken();

    $resolver = require __DIR__ . '/support/store.php';
    $store = $resolver();

    if ($store === null) {
        $this->xhr()->responseAsBody(['ok' => false, 'error' => 'no database connection available (queue store unresolvable)']);

        return;
    }

    require_once __DIR__ . '/support/QueueAdminService.php';

    // lint-allow: RZ-003 — form input cast immediately (manual/03 §4 sanctioned pattern).
    $queue = (string) ($_POST['queue'] ?? '');

    if ($queue === '') {
        $this->xhr()->responseCode(422)->responseAsBody(['ok' => false, 'error' => 'queue name is required (no implicit purge-all)']);

        return;
    }

    $service = new Razy\Module\queueadmin\QueueAdminService($store);
    $this->xhr()->responseAsBody($service->purge($queue));
};
