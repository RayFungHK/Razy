<?php

/**
 * This file is part of Razy.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 *
 * @license MIT
 */

namespace Razy\Authz;

use Closure;

/**
 * The gate's 403 answer surface — shape deliberately mirrored from
 * CsrfRejection (same XHR dialect, same never-echo-the-input posture,
 * same announce-only event). A denial names the ability that refused;
 * it never names WHY the predicate said no (the answer may itself be
 * sensitive), and it never echoes the request body.
 */
class Denial
{
    /**
     * @param Closure|null $moduleResolver fn(string $moduleCode): ?object —
     *                                     the same resolver shape the CSRF
     *                                     door ships (module emitter access)
     */
    public function __construct(
        private readonly ?Closure $moduleResolver = null,
    ) {
    }

    /**
     * Answer a refused authorization. Returns null per the middleware
     * contract (the response IS the request's last word).
     *
     * @param array<string, mixed> $context The routed context
     * @param string $ability The declared ability that refused
     * @param bool $undefined Whether the ability had no registered predicate
     *                        (a misconfiguration — named honestly as such)
     */
    public function __invoke(array $context, string $ability, bool $undefined = false): null
    {
        $module = (string) ($context['module'] ?? '');
        $route = (string) ($context['route'] ?? '');

        if ($this->moduleResolver !== null && $module !== '') {
            $target = ($this->moduleResolver)($module);

            if (\is_object($target) && \method_exists($target, 'createEmitter')) {
                $target->createEmitter('authz.failed')->resolve([
                    'module' => $module,
                    'route' => $route,
                    'ability' => $ability,
                    'undefined' => $undefined,
                ]);
            }
        }

        $wantsJson = \strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
            || \str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');

        \header('HTTP/1.1 403', true, 403);

        if ($wantsJson) {
            \header('Content-Type: application/json; charset=utf-8');
            echo \json_encode([
                'error' => 'authz-denied',
                'module' => $module,
                'ability' => $ability,
                'message' => $undefined
                    ? 'This route requires an ability nobody defined — the guard is misconfigured, so the request was refused.'
                    : 'The defined ability refused this request.',
            ], JSON_UNESCAPED_SLASHES);
        } else {
            \header('Content-Type: text/html; charset=utf-8');
            $a = \htmlspecialchars($ability, ENT_QUOTES, 'UTF-8');
            $m = \htmlspecialchars($module !== '' ? $module . ' :: ' . $route : 'the handler', ENT_QUOTES, 'UTF-8');
            $why = $undefined
                ? "The required ability <code>{$a}</code> has no registered definition, so this request was refused (fail-closed: a missing guard answers no one)."
                : "The ability <code>{$a}</code> evaluated to no for this request.";
            echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>403 — authorization refused</title></head>'
                . '<body style="font-family:system-ui,sans-serif;max-width:38rem;margin:4rem auto;padding:0 1rem">'
                . '<h1>403 — authorization refused</h1>'
                . "<p>{$why} The request never reached <code>{$m}</code>.</p>"
                . '<p><small>403 is the standing answer of the dist\'s Authz gate — subscribe to the <code>authz.failed</code> event to audit refusals.</small></p>'
                . '</body></html>';
        }

        return null;
    }
}
