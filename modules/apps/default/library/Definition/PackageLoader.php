<?php

/**
 * razymod/apps — reads an app package folder (manual/15-razy-apps.md §2) into one definition.
 *
 *   app.json               manifest (apps_schema, code, version, roles, menu, …)
 *   entities/<code>.json   one entity (fields, numbering, indexes)
 *   views/<entity>.json    that entity's views (list / form / board / detail)
 *   workflows/<code>.json  one workflow
 *   permissions.json       {rules: […]}
 *   i18n/<locale>.json     {key: text}
 *   fixtures/, tests/, CHANGELOG.md, README.md   not part of the definition
 *
 * The plan writes these files as YAML. Razy's YAML parser does not yet read
 * lists of maps correctly, so v1 packages are JSON and a .yaml / .yml file
 * is refused with package.yaml_unsupported rather than half-read.
 *
 * Bounded and closed: only the files above, no symlinks, no file escaping
 * the folder, at most 200 files of 512 KB each and 4 MB in total, file
 * names must match the code inside. Entities and workflows are ordered by
 * file name so the result does not depend on the filesystem.
 */

declare(strict_types=1);

namespace razymod\apps\Definition;

use JsonException;

final class PackageLoader
{
    private const MAX_FILES = 200;

    private const MAX_FILE_BYTES = 524_288;

    private const MAX_TOTAL_BYTES = 4_194_304;

    private const IGNORED = ['fixtures', 'tests', 'CHANGELOG.md', 'README.md'];

    /** @var list<Issue> */
    private array $issues = [];

    private int $files = 0;

    private int $bytes = 0;

    private string $root = '';

    /**
     * @return array{definition: ?array<string, mixed>, issues: list<Issue>}
     */
    public function load(string $dir): array
    {
        $this->issues = [];
        $this->files = 0;
        $this->bytes = 0;
        $root = \realpath($dir);
        if ($root === false || !\is_dir($root)) {
            return ['definition' => null, 'issues' => [Issue::error('/', 'package.missing', 'Package folder not found')]];
        }
        $this->root = $root;

        $allowed = ['app.json', 'entities', 'views', 'workflows', 'permissions.json', 'i18n'];
        foreach ($this->entries($root) as $name) {
            if (\in_array($name, self::IGNORED, true)) {
                continue;
            }
            if (\preg_match('/\.ya?ml$/', $name) === 1) {
                $this->error($name, 'package.yaml_unsupported', 'YAML package files are not read yet; use JSON');
            } elseif (!\in_array($name, $allowed, true)) {
                $this->error($name, 'package.unknown_file', "'{$name}' is not part of an app package");
            }
        }

        $def = $this->json('app.json');
        if (!\is_array($def)) {
            if ($def === null && $this->issues === []) {
                $this->error('app.json', 'package.missing', 'app.json is required');
            }

            return ['definition' => null, 'issues' => $this->issues];
        }
        foreach (['entities', 'workflows', 'permissions', 'i18n'] as $key) {
            if (\array_key_exists($key, $def)) {
                $this->error('app.json', 'package.inline', "'{$key}' belongs in its own file, not app.json");
            }
        }

        $views = [];
        foreach ($this->folder('views') as $code => $file) {
            $v = $this->json($file);
            if (\is_array($v)) {
                $views[$code] = $v;
            }
        }
        $entities = [];
        foreach ($this->folder('entities') as $code => $file) {
            $e = $this->json($file);
            if (!\is_array($e)) {
                continue;
            }
            if (($e['code'] ?? null) !== $code) {
                $this->error($file, 'package.name_mismatch', "{$file} must hold the entity '{$code}'");
            }
            if (isset($e['views'])) {
                $this->error($file, 'package.inline', "Views of '{$code}' belong in views/{$code}.json");
            }
            if (isset($views[$code])) {
                $e['views'] = $views[$code];
                unset($views[$code]);
            }
            $entities[] = $e;
        }
        foreach (\array_keys($views) as $code) {
            $this->error("views/{$code}.json", 'package.orphan_views', "There is no entities/{$code}.json");
        }
        $def['entities'] = $entities;

        $workflows = [];
        foreach ($this->folder('workflows') as $code => $file) {
            $w = $this->json($file);
            if (!\is_array($w)) {
                continue;
            }
            if (($w['code'] ?? null) !== $code) {
                $this->error($file, 'package.name_mismatch', "{$file} must hold the workflow '{$code}'");
            }
            $workflows[] = $w;
        }
        if ($workflows !== []) {
            $def['workflows'] = $workflows;
        }

        if (\is_file($root . '/permissions.json')) {
            $perm = $this->json('permissions.json');
            if (\is_array($perm)) {
                $def['permissions'] = $perm;
            }
        }

        $i18n = [];
        foreach ($this->folder('i18n') as $locale => $file) {
            $pack = $this->json($file);
            if (\is_array($pack)) {
                $i18n[$locale] = $pack;
            }
        }
        $def['i18n'] = $i18n;

        return ['definition' => $this->hasErrors() ? null : $def, 'issues' => $this->issues];
    }

    /**
     * @return list<string>
     */
    private function entries(string $dir): array
    {
        $names = \scandir($dir);
        if ($names === false) {
            return [];
        }
        $out = [];
        foreach ($names as $name) {
            if ($name !== '.' && $name !== '..') {
                $out[] = $name;
            }
        }
        \sort($out, SORT_STRING);

        return $out;
    }

    /**
     * The *.json files of a sub folder, keyed by base name, in name order.
     *
     * @return array<string, string> code => path relative to the package
     */
    private function folder(string $sub): array
    {
        $dir = $this->root . '/' . $sub;
        if (!\is_dir($dir) || \is_link($dir)) {
            if (\is_link($dir)) {
                $this->error($sub, 'package.symlink', 'Symbolic links are not allowed in a package');
            }

            return [];
        }
        $out = [];
        foreach ($this->entries($dir) as $name) {
            $rel = "{$sub}/{$name}";
            if (\preg_match('/^(.+)\.json$/', $name, $m) !== 1) {
                $code = \preg_match('/\.ya?ml$/', $name) === 1 ? 'package.yaml_unsupported' : 'package.unknown_file';
                $this->error($rel, $code, $code === 'package.yaml_unsupported'
                    ? 'YAML package files are not read yet; use JSON' : "'{$rel}' is not a .json file");
                continue;
            }
            $pattern = $sub === 'i18n' ? Schema::LOCALE : Schema::CODE;
            if (\preg_match($pattern, $m[1]) !== 1) {
                $this->error($rel, 'package.file_name', "'{$name}' is not a valid " . ($sub === 'i18n' ? 'locale' : 'code') . ' file name');
                continue;
            }
            $out[$m[1]] = $rel;
        }

        return $out;
    }

    private function json(string $rel): mixed
    {
        $path = $this->root . '/' . $rel;
        if (!\is_file($path)) {
            return null;
        }
        if (\is_link($path)) {
            $this->error($rel, 'package.symlink', 'Symbolic links are not allowed in a package');

            return null;
        }
        $real = \realpath($path);
        if ($real === false || !\str_starts_with($real, $this->root . DIRECTORY_SEPARATOR)) {
            $this->error($rel, 'package.outside', 'File resolves outside the package folder');

            return null;
        }
        $size = (int) \filesize($real);
        if (++$this->files > self::MAX_FILES || $size > self::MAX_FILE_BYTES || ($this->bytes += $size) > self::MAX_TOTAL_BYTES) {
            $this->error($rel, 'package.size', 'Package is over its size caps (200 files, 512 KB each, 4 MB total)');

            return null;
        }
        $raw = (string) \file_get_contents($real);
        try {
            $data = \json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->error($rel, 'package.json', 'Invalid JSON: ' . $e->getMessage());

            return null;
        }
        if (!\is_array($data) || ($data !== [] && \array_is_list($data))) {
            $this->error($rel, 'package.json', 'The file must hold a JSON object');

            return null;
        }

        return $data;
    }

    private function hasErrors(): bool
    {
        foreach ($this->issues as $issue) {
            if ($issue->severity === Issue::ERROR) {
                return true;
            }
        }

        return false;
    }

    private function error(string $file, string $code, string $message): void
    {
        $this->issues[] = Issue::error('/' . $file, $code, $message);
    }
}
