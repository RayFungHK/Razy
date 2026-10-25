<?php

/**
 * This file is part of Razy.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * @license MIT
 */

declare(strict_types=1);

namespace Razy\Rzx;

use Closure;
use Razy\Asset\Assets;
use Razy\Http\SecurityHeaders;
use Razy\ModuleInfo;
use Razy\Rzx\Runtime\Escape;
use Razy\Rzx\Runtime\RenderError;
use Razy\Template;
use Razy\Template\Plugin\TModifier;

/**
 * Razy's own expression functions for `.rzx`, reusing what `.tpl` uses:
 *
 * | Function            | Is                                                                   |
 * |---------------------|----------------------------------------------------------------------|
 * | `asset('js/x.js')`  | Assets::url() for the rendering module; `asset('alias:css/a.css')`   |
 * |                     | for another module's web assets                                      |
 * | `importmap()`       | the importmap `<script>` with the CSP nonce, once per response;      |
 * |                     | print it with `{html(importmap())}`                                  |
 * | `nonce()`           | this response's CSP nonce                                            |
 * | `url('path')`       | the site URL joined with a path                                      |
 * | `nl2br(text)`       | escaped text with `<br>` line breaks, as SafeHtml                    |
 * | modifiers           | every template modifier except `raw`, `escape` and `json` (built in): |
 * |                     | `upper(s)`, `date(ts, 'Y-m-d')`, `truncate(s, 40)`, `join(list, ',')` |
 *
 * A modifier runs the same plugin a `.tpl` `->name:arg` runs, so its
 * behaviour cannot drift; its result is a plain value, escaped like any other.
 */
final class RazyFunctions
{
    public const MODIFIERS = ['addslashes', 'alphabet', 'capitalize', 'date', 'gettype', 'join', 'lower', 'number', 'strip_tags', 'trim', 'truncate', 'upper'];

    /** @var list<array{Template, ?ModuleInfo, ?string}> template, module, asset alias override */
    private array $context = [];

    /**
     * @param Closure(): string $siteUrl
     */
    public function __construct(private readonly Closure $siteUrl)
    {
    }

    public function register(FunctionRegistry $functions): void
    {
        $functions
            ->register('asset', $this->asset(...))
            ->register('importmap', $this->importmap(...))
            ->register('nonce', static fn (): string => SecurityHeaders::current()->nonce())
            ->register('url', $this->url(...))
            ->register('nl2br', static fn (mixed $text): SafeHtml => new SafeHtml(\nl2br(Escape::text($text))));
        foreach (self::MODIFIERS as $name) {
            $functions->register($name, fn (mixed ...$args): mixed => $this->modifier($name, \array_values($args)));
        }
    }

    /**
     * Run $render with the template and module that functions refer to.
     * $alias names the module whose web assets `asset()` uses when there is
     * no module (a UI fixture).
     *
     * @template T
     *
     * @param Closure(): T $render
     *
     * @return T
     */
    public function within(Template $template, ?ModuleInfo $module, Closure $render, ?string $alias = null): mixed
    {
        $outer = \end($this->context) ?: [null, null, null];
        $this->context[] = [$template, $module ?? $outer[1], $alias ?? ($module === null ? $outer[2] : null)];

        try {
            return $render();
        } finally {
            \array_pop($this->context);
        }
    }

    /**
     * A module asset's URL. It is trusted (a SafeUrl, so `<script src>` takes it
     * even from a CDN) only when it stays inside that module's web assets; a
     * path with `..`, `.` or a control character is an error.
     */
    private function asset(string $path): SafeUrl
    {
        $alias = null;
        if (\preg_match('/^([A-Za-z0-9_.-]+):(?!\/\/)(.+)$/', $path, $m)) {
            [, $alias, $path] = $m;
        }
        $top = \end($this->context) ?: [null, null, null];
        $alias ??= $top[2] ?? $top[1]?->getAlias();
        if ($alias === null || $alias === '') {
            throw new RenderError("asset('" . $path . "') needs a module: write asset('alias:" . $path . "')");
        }
        $segments = \array_filter(\explode('/', \str_replace('\\', '/', $path)), static fn (string $s): bool => $s !== '');
        if ($segments === [] || \preg_match('/[\x00-\x1F\x7F]/', $path) || \array_intersect($segments, ['.', '..']) !== []) {
            throw new RenderError("asset('" . \addcslashes($path, "\0..\37") . "') is not a path inside the module's web assets");
        }
        $url = Assets::current()->url($alias, \implode('/', $segments));
        if (!\str_contains((string) \parse_url($url, \PHP_URL_PATH), '/webassets/' . \rawurlencode($alias) . '/')) {
            throw new RenderError("asset('" . $path . "') did not resolve inside /webassets/" . $alias . '/');
        }

        return new SafeUrl($url);
    }

    /**
     * The site URL joined with a path on this site. Trusted (SafeUrl) only when
     * the result stays on this origin; an absolute or protocol-relative path is
     * an error.
     */
    private function url(string $path = ''): SafeUrl|string
    {
        $path = \str_replace('\\', '/', $path);
        if (Escape::isProtocolRelative($path) || !Escape::isSameOrigin($path)) {
            throw new RenderError("url('" . $path . "') takes a path on this site, not an absolute URL");
        }
        $site = \rtrim(\str_replace('\\', '/', ($this->siteUrl)()), '/');
        $joined = $site . '/' . \ltrim($path, '/');
        $sameOrigin = $site === '' ? Escape::isSameOrigin($joined) : (bool) \preg_match('#^https?://[^/?\#@]+(?:/|$)#i', $site . '/');

        return $sameOrigin ? new SafeUrl($joined) : $joined;
    }

    private function importmap(): SafeHtml
    {
        $template = \end($this->context)[0] ?? null;
        if ($template !== null && !$template->anchors()->claimImportMap()) {
            return new SafeHtml('');
        }

        return new SafeHtml(Assets::current()->importMapTag(SecurityHeaders::current()->nonce()));
    }

    /**
     * @param list<mixed> $args
     */
    private function modifier(string $name, array $args): mixed
    {
        $template = \end($this->context)[0] ?? null;
        $plugin = $template?->loadPlugin('modifier', $name);
        if (!$plugin instanceof TModifier) {
            throw new RenderError('The template modifier ' . $name . ' is not available');
        }
        $value = \array_shift($args);
        $strings = \array_map(static fn (mixed $a): string => \is_bool($a) ? ($a ? 'true' : 'false') : Escape::stringify($a), $args);

        // The plugin's process() is protected: run it in the plugin's own scope.
        return (fn (mixed $value, string ...$args): mixed => $this->process($value, ...$args))->call($plugin, $value, ...$strings);
    }
}
