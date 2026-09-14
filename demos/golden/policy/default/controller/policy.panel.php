<?php
/**
 * golden/policy — lazy-route handler for /<module-alias>/panel.
 *
 * THE permission check, done the sanctioned way (manual/11 §route gating;
 * the razit production loop minus its security debt):
 *
 *   1. api() is NULLABLE (Controller.php:510) — the permission module may
 *      be absent from this distributor,
 *   2. the decision must be TRUE to act — `!== true` catches false AND
 *      null AND any future error shape in one comparison (fail-closed),
 *   3. the identity question is NOT asked here: the permission module
 *      resolves the session actor itself (session actor key / §7.5 CLI
 *      posture) — consumers never pass a user id and never touch the
 *      permission tables.
 *
 * The same decision in a template is one plugin call — server-side hiding,
 * the denied block never reaches the wire:
 *
 *     {@can 'golden.publish'}<a href="/golden/policy/publish">Publish</a>{/can}
 *
 * (the {can} plugin ships WITH razymod/permissions — no per-consumer glue.)
 */

use Razy\Controller;

return function (): void {
    /** @var Controller $this */
    $gate = $this->api('razymod/permissions');

    if ($gate === null) {
        // absent provider is a DENY, not a crash and not an allow
        // (XHR::responseCode :140 sets the status for the terminal send)
        $this->xhr()->responseCode(503)->responseAsBody([
            'ok'    => false,
            'error' => 'razymod/permissions not installed (declare it in package.php require + run compose)',
        ]);

        return;
    }

    $allowed = $gate->can('golden.publish');

    if ($allowed !== true) {
        $this->xhr()->responseCode(403)->responseAsBody([
            'ok'    => false,
            'error' => 'forbidden',
        ]);

        return;
    }

    // allowed payload — values still obey RZ-004 at any HTML boundary
    $this->xhr()->responseAsBody([
        'ok'    => true,
        'panel' => ['section' => 'publish', 'via' => 'razymod/permissions'],
    ]);
};
