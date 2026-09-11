<?php

/**
 * This file is part of Razy v1.0.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 *
 * @license MIT
 */

namespace Razy\Translation;

/**
 * Locale-aware message translation with namespaces, placeholders and plurals.
 *
 * Default locale is Traditional Chinese ('zh-Hant'); a fallback chain guarantees
 * every key resolves to *something* even when a locale is only partially filled.
 *
 * Key grammar:
 *   group.key[.nested]              → {path}/{locale}/group.php['key']['nested']
 *   vendor/module::group.key        → that module's registered lang path (RZ-001:
 *                                     each module owns its dictionary; nothing
 *                                     reaches into another module's files by hand)
 *
 * Placeholders:
 *   get('greeting', ['name' => 'Sam'])   with "Hello, :name!"  → "Hello, Sam!"
 *   Smart case: :name / :Name / :NAME render Sam / Sam / SAM when only 'name' given.
 *
 * Missing keys return the key string (so the UI shows a visible TODO, never throws).
 */
class Translator
{
    /** @var array<string, string> Dot-path → resolved string cache */
    private array $resolved = [];

    /** @var array<string, string> Additional namespace → base path map */
    private array $namespaces = [];

    private string $locale;

    /** @var list<string> */
    private array $fallback;

    /**
     * @param string $path Base lang directory (contains per-locale subfolders)
     * @param string $locale Active locale (default: Traditional Chinese)
     * @param string $fallback Fallback locale(s) when a key is absent in the active one
     */
    public function __construct(
        private readonly string $path,
        string $locale = 'zh-Hant',
        string|array $fallback = 'en',
        private readonly FileLoader $loader = new FileLoader(),
    ) {
        $this->locale = $locale;
        $this->fallback = \array_values(\array_unique((array) $fallback));
    }

    /**
     * @return array{0: ?string, 1: string} [namespace|null, key-after-namespace]
     */
    private static function splitNamespace(string $key): array
    {
        $at = \strpos($key, '::');

        if ($at === false) {
            return [null, $key];
        }

        return [\substr($key, 0, $at), \substr($key, $at + 2)];
    }

    /**
     * Replace :placeholders with smart-case variants (Laravel-compatible).
     *
     * @param array<string, string|int|float> $replace
     */
    private static function replace(string $line, array $replace, string $locale): string
    {
        if ($replace === []) {
            return $line;
        }

        $prefix = \strtolower(\explode('-', \str_replace('_', '-', $locale))[0]);
        $thousands = !\in_array($prefix, ['zh', 'ja', 'ko', 'th'], true);

        foreach ($replace as $name => $value) {
            // Numeric values get thousand separators BEFORE substitution (CJK locales keep raw digits).
            if ($thousands && (\is_int($value) || \is_float($value))) {
                $value = \number_format($value, \is_float($value) ? 2 : 0);
            } else {
                $value = (string) $value;
            }

            $lower = \strtolower((string) $name);

            $line = \str_replace(':' . $lower, $value, $line);
            $line = \str_replace(':' . \ucfirst($lower), \ucfirst($value), $line);
            $line = \str_replace(':' . \strtoupper($lower), \strtoupper($value), $line);
        }

        return $line;
    }

    // ── Configuration ─────────────────────────────────────────

    public function setLocale(string $locale): static
    {
        $this->locale = $locale;
        $this->resolved = [];

        return $this;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    /**
     * @param string|list<string> $fallback
     */
    public function setFallback(string|array $fallback): static
    {
        $this->fallback = \array_values(\array_unique((array) $fallback));

        return $this;
    }

    /**
     * Register (or override) the base lang directory for a namespace, e.g.
     *   addNamespace('core/shop', '/app/vendor/module/core/shop/default/lang').
     *
     * Keys for that dictionary are written 'core/shop::group.key'.
     */
    public function addNamespace(string $namespace, string $path): static
    {
        $this->namespaces[$namespace] = $path;
        $this->resolved = [];

        return $this;
    }

    // ── Retrieval ─────────────────────────────────────────────

    public function has(string $key, ?string $locale = null): bool
    {
        return $this->lookup($key, $locale ?? $this->locale) !== null;
    }

    /**
     * Translate $key, substituting $replace placeholders.
     *
     * @param array<string, string|int|float> $replace
     */
    public function get(string $key, array $replace = [], ?string $locale = null): string
    {
        $locale ??= $this->locale;
        $cacheKey = $locale . '|' . $key;

        if (isset($this->resolved[$cacheKey])) {
            $line = $this->resolved[$cacheKey];
        } else {
            $line = $this->lookup($key, $locale) ?? $this->lookupFallback($key);
            $this->resolved[$cacheKey] = $line;
        }

        // Unresolved keys return the key itself (visible, non-throwing).
        return $line === null ? $key : self::replace($line, $replace, $locale);
    }

    /**
     * Translate with pluralisation (see Pluralizer for line syntax).
     *
     * @param array<string, string|int|float> $replace :count is injected automatically
     */
    public function choice(string $key, int|float $number, array $replace = [], ?string $locale = null): string
    {
        $locale ??= $this->locale;
        $line = $this->lookup($key, $locale) ?? $this->lookupFallback($key) ?? $key;

        if ($line !== $key) {
            $line = Pluralizer::line($line, $number, $locale);
        }

        $replace = \array_merge(['count' => $number], $replace);

        return self::replace($line, $replace, $locale);
    }

    // ── Internal ──────────────────────────────────────────────

    /**
     * Resolve $key within one locale, or null when absent.
     */
    private function lookup(string $key, string $locale): ?string
    {
        [$namespace, $remainder] = self::splitNamespace($key);
        $segments = \explode('.', $remainder);

        if (\count($segments) < 2) {
            return null;
        }

        $group = \array_shift($segments);
        $base = $namespace === null
            ? $this->path
            : ($this->namespaces[$namespace] ?? null);

        if ($base === null) {
            return null;
        }

        $line = $this->loader->load($base, $locale, $group);

        foreach ($segments as $segment) {
            if (!\is_array($line) || !\array_key_exists($segment, $line)) {
                return null;
            }
            $line = $line[$segment];
        }

        return \is_string($line) ? $line : null;
    }

    private function lookupFallback(string $key): ?string
    {
        foreach ($this->fallback as $fallbackLocale) {
            if ($fallbackLocale !== $this->locale) {
                $value = $this->lookup($key, $fallbackLocale);

                if ($value !== null) {
                    return $value;
                }
            }
        }

        return null;
    }
}
