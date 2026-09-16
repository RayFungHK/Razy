<?php

/**
 * razymod/queue-admin — guarded mutation route (POST /<module-alias>/act).
 *
 * Guards, in order: POST-only (405), the dist's armed CSRF door (419 —
 * CSRF-RAIL.md L4; the handler itself refuses to run on an unarmed dist),
 * then the service's own existence/kind validation (ok:false, no
 * exceptions). The module's existing __onAPICall tightening note applies:
 * this HTTP surface inherits the operator-trust boundary of the route
 * itself — deploy behind your edge auth like any admin panel.
 */

use Razy\Controller;

return function (): void {
    /** @var Controller $this */
    // lint-allow: RZ-003 — method gate for this POST-only endpoint, compared to a constant.
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        $this->xhr()->responseCode(405)->responseAsBody(['ok' => false, 'error' => 'POST required']);

        return;
    }

    // CSRF is the armed dist's door now (CSRF-RAIL.md L4): a request only
    // reaches this handler with a validated token (this route carries no
    // exemption). Resolving the manager doubles as the fail-closed guard —
    // an UNARMED dist throws HERE rather than running an admin mutation
    // surface unprotected (the old hand-rolled double-submit defended itself
    // anywhere; the door defends better where armed and says so loudly where
    // not). Mismatch answers are the door's 419, not a handler-level 403.
    $this->csrfToken();

    $resolver = require __DIR__ . '/support/store.php';
    $store = $resolver();

    if ($store === null) {
        $this->xhr()->responseAsBody(['ok' => false, 'error' => 'no database connection available (queue store unresolvable)']);

        return;
    }

    require_once __DIR__ . '/support/QueueAdminService.php';

    // lint-allow: RZ-003 — form inputs cast immediately (manual/03 §4 sanctioned pattern).
    $id = (string) ($_POST['id'] ?? '');
    // lint-allow: RZ-003 — kind is re-validated against QueueAdminService::KINDS inside action(); cast per pattern.
    $kind = (string) ($_POST['kind'] ?? '');
    // lint-allow: RZ-003 — numeric cast, bounded below by the service itself.
    $retryDelay = (int) ($_POST['retry_delay'] ?? 0);

    if ($id === '' || $kind === '') {
        $this->xhr()->responseCode(422)->responseAsBody(['ok' => false, 'error' => 'id and kind are required']);

        return;
    }

    $service = new Razy\Module\queueadmin\QueueAdminService($store);
    $this->xhr()->responseAsBody($service->action($id, $kind, $retryDelay));
};
