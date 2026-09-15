<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Razy\Routing;

use Razy\Module;

/**
 * Build-time route audit for FM-2 (Route Coexistence Phase 3, dossier
 * option (f): "catch-all route audit in `validate` — cheap insurance for
 * FM-2's PHP-side half").
 *
 * FM-2's worst outcome is a 200 from the WRONG app: a standard route that
 * compiles to a distributor-root catch-all (`addRoute('/')` →
 * `/^(\/)((?:.+)?)`, dossier §1.6) or an unanchored tail (`/user/profile`
 * also serving `/user/profileX/anything`) swallows foreign namespaces that
 * reach PHP; a lazy route at a module-root alias swallows every deeper path
 * the web server already handed over, including sibling mounts "under" the
 * alias's URL space.
 *
 * The audit is FUNCTIONAL, not string-pattern heuristic: it probes each
 * standard route's ALREADY-COMPILED regex (`RouteDispatcher::setRoute()`
 * `:196`, engine truth by construction) against synthetic foreign paths, so
 * whatever the real engine matches is exactly what gets flagged.
 *
 * Legitimate catch-alls (a dist that genuinely owns every path) opt out in
 * `dist.php`:
 *
 *     'route_audit_allow' => ['demo/hello_world:/', 'vendor/mod:/alias'],
 *
 * matching `module_code:<route>` (standard: the registered pattern; lazy:
 * the alias-bound route_path). The allow list lives in dist.php because
 * catch-alls being safe is a SITE-topology decision, not a module property
 * — the same placement logic as Q1 putting exclusions in site config.
 */
final class RouteAudit
{
    /**
     * Synthetic path segment used as probe payload. Underscore-word form
     * matches the engine's `:a` (`[^/]+`) and `:d`-adjacent classes without
     * colliding with realistic literal segments.
     */
    public const PROBE_SEGMENT = '__razy_audit_probe__';

    public const RULE_ROOT_CLAIM = 'root_claim';

    public const RULE_ABSORBS_FOREIGN = 'absorbs_foreign';

    public const RULE_LAZY_SHADOWS = 'lazy_shadows';

    /**
     * Run the audit over one distributor's route table.
     *
     * @param array<string, array> $routes RouteDispatcher::getRoutes() shape
     *                                     (entries need 'type'; standard: 'route' +
     *                                     'compiled_regex' + 'module_code'; lazy: 'route_path').
     * @param array<string, string> $foreignPrefixes Host-absolute prefixes that are
     *                                               NOT this distributor's URL space, keyed prefix =>
     *                                               human label (sibling mounts from the domain table,
     *                                               declared `exclude_paths`). '/' is meaningless as a
     *                                               foreign key here (root claim already covers it) —
     *                                               callers filter it out.
     * @param list<string> $allow dist.php `route_audit_allow` entries ('module_code:route').
     *
     * @return array{findings: list<array{rule: string, route_key: string, module_code: string, route: string, prefix: string, message: string}>, suppressed: int}
     */
    public static function run(array $routes, array $foreignPrefixes, array $allow = []): array
    {
        $findings = [];
        $suppressed = 0;

        foreach ($routes as $routeKey => $info) {
            $type = (string) (($info['type'] ?? 'standard'));
            $moduleCode = self::moduleCode($info);

            if ('standard' === $type) {
                $pattern = (string) ($info['route'] ?? $routeKey);
                $compiled = (string) ($info['compiled_regex'] ?? '');

                if ('' === $compiled) {
                    // Entries registered through setRoute() always carry the compiled
                    // regex; an entry without it did not come from the route table we
                    // understand — skip rather than guess (never fabricate a finding).
                    continue;
                }

                $allowKey = $moduleCode . ':' . $pattern;

                // Rule A: the route matches an arbitrary foreign path outright ⇒
                // distributor-root claim (FM-2's demo footgun, doc-drift D5).
                if (1 === \preg_match($compiled, self::probePath(''))) {
                    $finding = self::finding(
                        self::RULE_ROOT_CLAIM,
                        (string) $routeKey,
                        $moduleCode,
                        $pattern,
                        '',
                        'route compiles to a distributor-root catch-all: it serves EVERY path that reaches this distributor\'s PHP (200-from-wrong-app class).',
                    );

                    if (\in_array($allowKey, $allow, true)) {
                        ++$suppressed;
                    } else {
                        $findings[] = $finding;
                    }

                    // A root-claim route trivially matches every foreign probe too —
                    // reporting the stronger finding once, not per prefix.
                    continue;
                }

                // Rule B: the route matches paths under a foreign prefix — either it
                // was registered inside someone else's namespace, or the unanchored
                // tail absorbs a sibling/exclusion that merely shares a prefix.
                foreach ($foreignPrefixes as $prefix => $label) {
                    if (1 !== \preg_match($compiled, self::probePath((string) $prefix))) {
                        continue;
                    }

                    if (\in_array($allowKey, $allow, true)) {
                        ++$suppressed;

                        continue;
                    }

                    $findings[] = self::finding(
                        self::RULE_ABSORBS_FOREIGN,
                        (string) $routeKey,
                        $moduleCode,
                        $pattern,
                        (string) $prefix,
                        'route matches paths under ' . $label . ' — if the web-server claim is ever breached, PHP serves them from THIS app.',
                    );
                }

                continue;
            }

            if ('lazy' === $type) {
                $bound = '/' . \trim((string) ($info['route_path'] ?? ''), '/');

                // The bound prefix must be a single safe chain before any
                // comparison is meaningful — a literal '*' or brace in a stored
                // route_path would make a str_starts_with claim fictitious.
                // (Registration tidies input; this guard is belt-and-braces.)
                if (1 !== \preg_match('#^[\w./-]+$#', $bound)) {
                    continue;
                }

                $allowKey = $moduleCode . ':' . $bound;

                // Rule C: lazy dispatch is prefix-bound (str_starts_with on
                // '<route_path>/' — dossier FM-2 confirmed case), so any foreign
                // prefix AT or BELOW the alias is swallowed once it reaches PHP.
                foreach ($foreignPrefixes as $prefix => $label) {
                    $foreign = '/' . \trim((string) $prefix, '/');

                    // Equality (foreign mount exactly at the alias) falls out of the
                    // same '/'+appended comparison: identical strings always prefix-match.
                    if (!\str_starts_with($foreign . '/', $bound . '/')) {
                        continue;
                    }

                    if (\in_array($allowKey, $allow, true)) {
                        ++$suppressed;

                        continue;
                    }

                    $findings[] = self::finding(
                        self::RULE_LAZY_SHADOWS,
                        (string) $routeKey,
                        $moduleCode,
                        $bound,
                        (string) $prefix,
                        'lazy route at ' . $bound . ' prefix-shadows ' . $label . ' — every deeper path the web server hands to PHP is swallowed.',
                    );
                }
            }
        }

        return ['findings' => $findings, 'suppressed' => $suppressed];
    }

    /**
     * Synthetic foreign path used as probe payload: '<under>/<SEGMENT>/deep-path'
     * (root variant when $under is empty). Deliberately multi-segment so
     * routes that merely match a prefix still trip the unanchored-tail class.
     */
    public static function probePath(string $under = ''): string
    {
        $under = '' === $under ? '' : '/' . \trim($under, '/');

        return $under . '/' . self::PROBE_SEGMENT . '/deep-path';
    }

    /**
     * @param array $info route-table entry
     */
    private static function moduleCode(array $info): string
    {
        if (isset($info['module_code']) && \is_string($info['module_code'])) {
            return $info['module_code'];
        }

        // setLazyRoute() stores the Module object without the code
        // (RouteDispatcher.php:256-263) — resolve from the live entry.
        if (($info['module'] ?? null) instanceof Module) {
            return $info['module']->getModuleInfo()->getCode();
        }

        return '?';
    }

    /**
     * @return array{rule: string, route_key: string, module_code: string, route: string, prefix: string, message: string}
     */
    private static function finding(string $rule, string $routeKey, string $moduleCode, string $route, string $prefix, string $message): array
    {
        return [
            'rule' => $rule,
            'route_key' => $routeKey,
            'module_code' => $moduleCode,
            'route' => $route,
            'prefix' => $prefix,
            'message' => $message,
        ];
    }
}
