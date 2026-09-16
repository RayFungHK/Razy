<?php

/**
 * razymod/queue-admin — HTML shell route (GET /<module-alias>/ui).
 *
 * Self-contained single-page dashboard over this module's own JSON feeds
 * (/status, /job) and POST actions (/act, /purge). Deliberately NO wrapper
 * dependency on demo modules (RZ-001: demo/demo_index is not a production
 * surface). All dynamic rendering happens CLIENT-side via textContent —
 * the server-side template carries only two interpolated values, both
 * escaped at the boundary (RZ-004; the engine does not auto-escape).
 */

use Razy\Controller;

return function (): void {
    /** @var Controller $this */
    header('Content-Type: text/html; charset=UTF-8');

    // The dist's armed CSRF door owns the token now (CSRF-RAIL.md L4): the
    // session-backed value matches the X-CSRF-Token header the shell's fetch
    // already sends (name matches CsrfMiddleware::TOKEN_HEADER
    // case-insensitively). On an UNARMED dist this call throws loudly — the
    // admin panel refuses to render a form it cannot defend.
    $token = $this->csrfToken();

    $source = $this->loadTemplate('shell');
    $source->assign([
        // Pre-encoded JS string literal: JSON_HEX_TAG makes </script> injection
        // impossible, so the raw template interpolation stays honest (RZ-004).
        'module_url_json' => \json_encode(\rtrim($this->getModuleURL(), '/'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
        'csrf_token' => $token,
    ]);

    echo $source->output();
};
