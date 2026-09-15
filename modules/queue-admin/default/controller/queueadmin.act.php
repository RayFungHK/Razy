<?php

/**
 * razymod/queue-admin — guarded mutation route (POST /<module-alias>/act).
 *
 * Guards, in order: POST-only (405), CSRF double-submit (403), then the
 * service's own existence/kind validation (ok:false, no exceptions). The
 * module's existing __onAPICall tightening note applies: this HTTP surface
 * inherits the operator-trust boundary of the route itself — deploy behind
 * your edge auth like any admin panel.
 */

use Razy\Controller;

return function (): void {
    /** @var Controller $this */
    // lint-allow: RZ-003 — method gate for this POST-only endpoint, compared to a constant.
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        $this->xhr()->responseCode(405)->responseAsBody(['ok' => false, 'error' => 'POST required']);

        return;
    }

    /** @var array{issue: callable, verify: callable} $csrf */
    $csrf = require __DIR__ . '/support/csrf.php';

    if ($csrf['verify']() !== true) {
        $this->xhr()->responseCode(403)->responseAsBody(['ok' => false, 'error' => 'CSRF token missing or mismatched']);

        return;
    }

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
