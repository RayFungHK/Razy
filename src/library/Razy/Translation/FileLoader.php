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
 * Loads translation groups from PHP files that return arrays.
 *
 * Layout (one directory per locale):
 *   {path}/{locale}/{group}.php   →  return ['key' => 'value', 'nested' => ['k' => 'v']];
 *
 * Files are PHP (not JSON) so authors can use constants/heredoc and so opcache
 * makes them as cheap as the rest of the framework's config. Loaded groups are
 * memoised per (namespace, group, locale).
 */
class FileLoader
{
    /** @var array<string, array<string, mixed>> memo cache keyed by fullPath */
    private array $loaded = [];

    /**
     * Load a group for a locale from a base path, merging optional overrides.
     *
     * @param string $path Directory that contains a per-locale subfolder
     * @param string $locale Locale folder name (e.g. 'zh-Hant', 'en')
     * @param string $group File basename without .php
     *
     * @return array<string, mixed>
     */
    public function load(string $path, string $locale, string $group): array
    {
        $file = \rtrim($path, '/\\') . DIRECTORY_SEPARATOR . $locale . DIRECTORY_SEPARATOR . $group . '.php';

        if (isset($this->loaded[$file])) {
            return $this->loaded[$file];
        }

        if (!\is_file($file)) {
            return $this->loaded[$file] = [];
        }

        $data = require $file;

        return $this->loaded[$file] = \is_array($data) ? $data : [];
    }

    /** Drop the memo cache (test seam / hot reload). */
    public function flush(): void
    {
        $this->loaded = [];
    }
}
