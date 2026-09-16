<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 *
 * @license MIT
 */

namespace Razy\Csrf;

use Closure;

/**
 * The armed door's rejection answer surface (CSRF-RAIL.md Q5).
 *
 * Used as CsrfMiddleware's `onMismatch`: fires the `csrf.failed` event
 * (announce-only — auditors subscribe; the door stores nothing) and sends
 * the response itself. Answer shapes deliberately mirror the L3 readiness
 * gate: XHR/Accept:json gets a machine envelope, browsers get a small
 * honest page, and the submitted token is NEVER echoed anywhere.
 *
 * The event needs a Module (events hang off the registry per-module), so
 * the door injects a module resolver; without one the response still
 * stands and only the event is skipped (announce, never gate).
 */
class CsrfRejection
{
    /**
     * @param Closure|null $moduleResolver fn(string $moduleCode): ?object — an
     *                                     object exposing createEmitter(), or
     *                                     null when unresolvable
     */
    public function __construct(
        private readonly ?Closure $moduleResolver = null,
    ) {
    }

    /**
     * Answer a failed validation. Returns null per the CsrfMiddleware
     * onMismatch contract (validation already short-circuits — this return
     * value is the request's last word, the handler never runs).
     *
     * @param array<string, mixed> $context The middleware context (routedInfo)
     */
    public function __invoke(array $context): null
    {
        $module = (string) ($context['module'] ?? '');
        $route = (string) ($context['route'] ?? '');
        $method = \strtoupper((string) ($context['method'] ?? ''));

        if ($this->moduleResolver !== null && $module !== '') {
            $target = ($this->moduleResolver)($module);

            // Not an api() Emitter probe — this is a framework-internal
            // call on a resolved Module object (RZ-017's trap doesn't apply).
            if (\is_object($target) && \method_exists($target, 'createEmitter')) {
                $target->createEmitter('csrf.failed')->resolve([
                    'module' => $module,
                    'route' => $route,
                    'method' => $method,
                ]);
            }
        }

        $fix = 'refresh the page and resend the X-CSRF-TOKEN header (forms: include the _token field)';
        // Same detection pair as the L3 503 answer (RouteDispatcher
        // ::respondNotReady) — one XHR dialect across refusals.
        $wantsJson = \strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
            || \str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');

        \header('HTTP/1.1 419', true, 419);

        if ($wantsJson) {
            \header('Content-Type: application/json; charset=utf-8');
            echo \json_encode([
                'error' => 'csrf-token-mismatch',
                'module' => $module,
                'message' => 'The CSRF token is missing or no longer valid for this session.',
                'fix' => $fix,
            ], JSON_UNESCAPED_SLASHES);
        } else {
            \header('Content-Type: text/html; charset=utf-8');
            echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>419 — unknown request origin</title></head>'
                . '<body style="font-family:system-ui,sans-serif;max-width:38rem;margin:4rem auto;padding:0 1rem">'
                . '<h1>419 — unknown request origin</h1>'
                . '<p>The submitted CSRF token is missing or belongs to another session, '
                . 'so this <code>' . \htmlspecialchars($method, ENT_QUOTES, 'UTF-8') . '</code> request was refused '
                . 'before it reached <code>' . \htmlspecialchars($module !== '' ? $module . ' :: ' . $route : 'the handler', ENT_QUOTES, 'UTF-8') . '</code>.</p>'
                . '<p>' . \htmlspecialchars($fix, ENT_QUOTES, 'UTF-8') . '.</p>'
                . '<p><small>419 is a non-standard status meaning "Precondition Failed (CSRF token mismatch)" — '
                . 'widely used for exactly this answer. Never store and resubmit a token across sessions.</small></p>'
                . '</body></html>';
        }

        return null;
    }
}
