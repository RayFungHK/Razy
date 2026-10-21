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
use LogicException;
use Razy\Exception\ConfigurationException;
use Razy\Http\Headers;

/**
 * The gate's refusal answer surface — shape deliberately mirrored from
 * CsrfRejection (same XHR dialect, same never-echo-the-input posture,
 * same announce-only event). It never says WHY a predicate said no.
 *
 * RZ-06: 401 for a signed-out request (an HTML GET is redirected to the
 * dist's `authz.login_url` with `?next=`), 403 otherwise. A dist can replace
 * the rendering (`$agent->onAuthzDenied(fn (Refusal $r) => …)`). The default
 * HTML pages name no module, route or ability; the JSON 403 keeps the
 * ability name for clients. The `authz.failed` event and the error log carry
 * the details.
 */
class Denial
{
    /**
     * @param Closure|null $moduleResolver fn(string $moduleCode): ?object (module emitter access)
     * @param string|null $loginUrl local path a signed-out HTML GET is sent to (RZ-06)
     * @param Closure|null $renderer fn(Refusal $refusal): void — replaces the default output
     */
    private ?string $rendererOwner = null;

    public function __construct(
        private readonly ?Closure $moduleResolver = null,
        private readonly ?string $loginUrl = null,
        private ?Closure $renderer = null,
    ) {
    }

    /**
     * Answer a refused authorization. Returns null per the middleware
     * contract (the response IS the request's last word). A signed-out
     * refusal arrives with `$context['unauthenticated'] = true` (RZ-06).
     *
     * @param array<string, mixed> $context
     */
    public function __invoke(array $context, string $ability, bool $undefined = false): null
    {
        $unauthenticated = ($context['unauthenticated'] ?? false) === true;
        $module = (string) ($context['module'] ?? '');
        $route = (string) ($context['route'] ?? '');
        $status = $unauthenticated ? 401 : 403;

        if ($this->moduleResolver !== null && $module !== '') {
            $target = ($this->moduleResolver)($module);

            if (\is_object($target) && \method_exists($target, 'createEmitter')) {
                $target->createEmitter('authz.failed')->resolve([
                    'module' => $module,
                    'route' => $route,
                    'ability' => $ability,
                    'undefined' => $undefined,
                    'unauthenticated' => $unauthenticated,
                ]);
            }
        }

        if ($undefined) {
            \error_log("[Razy] authz: route '{$route}' ({$module}) requires undefined ability '{$ability}'; refused (fail-closed).");
        }

        $refusal = new Refusal($status, $ability, $undefined, $unauthenticated, $context, $this->loginUrl);
        if ($this->renderer !== null) {
            ($this->renderer)($refusal);
        } else {
            $this->render($refusal);
        }

        return null;
    }

    /**
     * Build from dist.php `'authz'` (keys: `login_url`).
     *
     * @param array<string, mixed> $authz
     *
     * @throws ConfigurationException If login_url is not a local absolute path.
     */
    public static function fromConfig(array $authz, ?Closure $moduleResolver = null, ?Closure $renderer = null): self
    {
        $unknown = \array_diff(\array_keys($authz), ['login_url']);
        if ($unknown !== []) {
            throw new ConfigurationException("Unknown dist.php 'authz' option(s): " . \implode(', ', $unknown) . '.');
        }
        $login = $authz['login_url'] ?? null;
        if ($login !== null && !self::isLocalPath($login)) {
            throw new ConfigurationException("dist.php 'authz.login_url' must be a local path like '/login'.");
        }

        return new self($moduleResolver, $login, $renderer);
    }

    private static function isLocalPath(mixed $path): bool
    {
        return \is_string($path)
            && \str_starts_with($path, '/')
            && !\str_starts_with($path, '//')
            && !\str_starts_with($path, '/\\')
            && !\preg_match('/[\x00-\x1f\x7f]/', $path);
    }

    /**
     * Replace the default output for this dist (`$agent->onAuthzDenied()`).
     * One module owns it; a second module cannot silently take over.
     *
     * @param Closure(Refusal): void $renderer
     *
     * @throws LogicException
     */
    public function setRenderer(Closure $renderer, string $owner): void
    {
        if ($this->rendererOwner !== null && $this->rendererOwner !== $owner) {
            throw new LogicException("The dist authz denial renderer is owned by '{$this->rendererOwner}'; '{$owner}' cannot replace it.");
        }
        $this->renderer = $renderer;
        $this->rendererOwner = $owner;
    }

    public function getLoginUrl(): ?string
    {
        return $this->loginUrl;
    }

    /**
     * Send a header. Protected seam so tests can record instead of sending.
     */
    protected function sendHeader(string $header, int $status = 0): void
    {
        if (Headers::sent()) {
            return;
        }
        $status > 0 ? Headers::send($header, true, $status) : Headers::send($header, true);
    }

    private function render(Refusal $refusal): void
    {
        $wantsJson = \strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
            || \str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
        $method = \strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

        if ($refusal->unauthenticated && !$wantsJson && $this->loginUrl !== null && ($method === 'GET' || $method === 'HEAD')) {
            $next = (string) ($_SERVER['REQUEST_URI'] ?? '/');
            $next = self::isLocalPath($next) ? $next : '/';
            $glue = \str_contains($this->loginUrl, '?') ? '&' : '?';
            $this->sendHeader('Location: ' . $this->loginUrl . $glue . 'next=' . \rawurlencode($next), 302);

            return;
        }

        $this->sendHeader('HTTP/1.1 ' . $refusal->status, $refusal->status);

        if ($wantsJson) {
            $this->sendHeader('Content-Type: application/json; charset=utf-8');
            $payload = $refusal->unauthenticated
                ? ['error' => 'unauthenticated', 'message' => 'Sign in to continue.'] + ($this->loginUrl !== null ? ['login' => $this->loginUrl] : [])
                : [
                    'error' => 'authz-denied',
                    'ability' => $refusal->ability,
                    'message' => $refusal->undefined
                        ? 'This route requires an ability nobody defined — the guard is misconfigured, so the request was refused.'
                        : 'The defined ability refused this request.',
                ];
            echo \json_encode($payload, JSON_UNESCAPED_SLASHES);

            return;
        }

        $this->sendHeader('Content-Type: text/html; charset=utf-8');
        [$title, $text] = $refusal->unauthenticated
            ? ['401 — sign in required', 'You need to sign in to see this page.']
            : ['403 — access refused', 'You do not have access to this page.'];
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="robots" content="noindex"><title>' . $title . '</title></head>'
            . '<body style="font-family:system-ui,sans-serif;max-width:38rem;margin:4rem auto;padding:0 1rem">'
            . '<h1>' . $title . '</h1><p>' . $text . '</p></body></html>';
    }
}
