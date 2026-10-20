<?php

/**
 * This file is part of Razy.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Razy\Asset;

use Closure;
use InvalidArgumentException;
use Razy\Exception\ConfigurationException;
use Razy\Http\StaticFile;
use Razy\Module;
use Razy\Module\ModuleStatus;
use Razy\Util\PathUtil;
use stdClass;

/**
 * The dist's web assets (RZ-09): one content-hashed URL per file, the ES
 * module importmap that makes every import of a file resolve to that URL,
 * and an optional PHP server for `/webassets/{alias}/{version}/{file}`.
 *
 * URLs keep the layout of {@see \Razy\Controller::getAssetPath()} and add
 * `?v=<hash>`, so the generated rewrite/Caddy rules serve them unchanged.
 * Templates use `{@asset file='js/app.js'}` and `{@importmap}`.
 *
 * Configured by dist.php 'assets' (every key optional; absent = defaults):
 * - `serve` (bool): answer `/webassets/...` requests in PHP, before the
 *   session and routing. Default: on under PHP's built-in server
 *   (`php Razy.phar serve`), off elsewhere — the web server serves them;
 * - `max_age` (int, default 0): Cache-Control max-age for a URL without the
 *   current hash (0 = revalidate every time; ETag/304 apply);
 * - `immutable_max_age` (int, default 31536000): max-age for a URL with the
 *   current hash, sent with `immutable`;
 * - `base_url` (string): prefix for asset URLs instead of the dist's path
 *   (a CDN origin or another path);
 * - `imports` (`[specifier => 'alias/path/file.js']`): bare specifiers added
 *   to the importmap.
 *
 * @license MIT
 */
final class Assets
{
    private const KEYS = ['serve', 'max_age', 'immutable_max_age', 'base_url', 'imports'];

    /** @var list<string> Files the importmap lists */
    private const SCRIPT_EXTENSIONS = ['js', 'mjs'];

    /** @var self|null The dist serving this request; the template functions read it */
    private static ?self $current = null;

    /** @var (Closure(): array<string, array{version: string, root: string}>)|null */
    private ?Closure $modules = null;

    /** @var (Closure(): string)|null */
    private ?Closure $siteUrl = null;

    /** @var array<string, array{version: string, manifest: AssetManifest}>|null Alias => module, built once */
    private ?array $roots = null;

    /**
     * @param bool $serve Answer /webassets/ requests in PHP
     * @param int $maxAge max-age for URLs without the current hash
     * @param int $immutableMaxAge max-age for URLs with it
     * @param string|null $baseUrl URL prefix ending in '/', null = the dist's path
     * @param array<string, array{0: string, 1: string}> $imports Specifier => [alias, file]
     */
    private function __construct(
        private readonly bool $serve,
        private readonly int $maxAge,
        private readonly int $immutableMaxAge,
        private readonly ?string $baseUrl,
        private readonly array $imports,
    ) {
    }

    /**
     * Read dist.php 'assets'.
     *
     * @param array<string, mixed> $config The dist.php array
     * @param string $distCode The dist code, for the error message
     *
     * @return self
     *
     * @throws ConfigurationException On an unknown key or an invalid value
     */
    public static function fromConfig(array $config, string $distCode): self
    {
        $raw = $config['assets'] ?? [];
        if (!\is_array($raw)) {
            throw self::invalid($distCode, 'must be an array', $raw);
        }
        foreach (\array_keys($raw) as $key) {
            if (!\in_array($key, self::KEYS, true)) {
                throw self::invalid($distCode, "has an unknown key '{$key}' (known: " . \implode(', ', self::KEYS) . ')');
            }
        }

        $serve = $raw['serve'] ?? ('cli-server' === PHP_SAPI);
        if (!\is_bool($serve)) {
            throw self::invalid($distCode, "'serve' must be true or false", $serve);
        }

        $baseUrl = $raw['base_url'] ?? null;
        if (null !== $baseUrl) {
            if (!\is_string($baseUrl) || \preg_match('/[\x00-\x20\x7F]/', $baseUrl) || !\preg_match('~^(?:https?://[^/]|/)~', $baseUrl)) {
                throw self::invalid($distCode, "'base_url' must be a single-line URL starting with http(s):// or /", $baseUrl);
            }
            $baseUrl = \rtrim($baseUrl, '/') . '/';
        }

        $imports = [];
        $rawImports = $raw['imports'] ?? [];
        if (!\is_array($rawImports)) {
            throw self::invalid($distCode, "'imports' must be an array of specifier => 'alias/path/file.js'", $rawImports);
        }
        foreach ($rawImports as $specifier => $target) {
            if (!\is_string($specifier) || '' === $specifier || \preg_match('/[\x00-\x1F\x7F]/', $specifier)) {
                throw self::invalid($distCode, "'imports' has an invalid specifier", $specifier);
            }
            $parts = \is_string($target) ? \explode('/', \trim($target, '/'), 2) : [];
            if (2 !== \count($parts) || '' === $parts[0] || null === self::cleanPath($parts[1])) {
                throw self::invalid($distCode, "'imports.{$specifier}' must be 'alias/path/file.js'", $target);
            }
            $imports[$specifier] = [$parts[0], (string) self::cleanPath($parts[1])];
        }

        return new self(
            $serve,
            self::seconds($raw['max_age'] ?? 0, 'max_age', $distCode),
            self::seconds($raw['immutable_max_age'] ?? 31536000, 'immutable_max_age', $distCode),
            $baseUrl,
            $imports,
        );
    }

    /**
     * The webassets folders of a dist's running modules, keyed by alias
     * (the first module wins an alias, as in the generated rewrite rules).
     *
     * @param iterable<Module> $modules
     *
     * @return array<string, array{version: string, root: string}>
     */
    public static function moduleRoots(iterable $modules): array
    {
        $roots = [];
        foreach ($modules as $module) {
            if (!\in_array($module->getStatus(), [ModuleStatus::InQueue, ModuleStatus::Loaded], true)) {
                continue;
            }
            $info = $module->getModuleInfo();
            $root = PathUtil::append($info->getPath(), 'webassets');
            if (!isset($roots[$info->getAlias()]) && \is_dir($root)) {
                $roots[$info->getAlias()] = ['version' => $info->getVersion(), 'root' => $root];
            }
        }

        return $roots;
    }

    /**
     * The assets of the dist serving this request, or an empty set before a
     * dist has booted.
     *
     * @return self
     */
    public static function current(): self
    {
        return self::$current ??= self::fromConfig([], '');
    }

    /**
     * @param self|null $assets Null resets (worker mode, per request)
     */
    public static function setCurrent(?self $assets): void
    {
        self::$current = $assets;
    }

    /**
     * A `/`-separated path with no empty, `.` or `..` segments, or null.
     */
    private static function cleanPath(string $path): ?string
    {
        $segments = \array_values(\array_filter(\explode('/', \str_replace('\\', '/', $path)), static fn (string $s): bool => '' !== $s));
        if (!$segments || \preg_match('/[\x00-\x1F\x7F]/', $path)) {
            return null;
        }
        foreach ($segments as $segment) {
            if ('.' === $segment || '..' === $segment) {
                return null;
            }
        }

        return \implode('/', $segments);
    }

    private static function seconds(mixed $value, string $key, string $distCode): int
    {
        if (!\is_int($value) || $value < 0) {
            throw self::invalid($distCode, "'{$key}' must be a number of seconds (int >= 0)", $value);
        }

        return $value;
    }

    private static function invalid(string $distCode, string $problem, mixed $value = null): ConfigurationException
    {
        $got = \func_num_args() > 2
            ? ', got: ' . (\is_scalar($value) ? \var_export($value, true) : \get_debug_type($value))
            : '';

        return new ConfigurationException("dist.php 'assets' {$problem} for dist '{$distCode}'{$got}.");
    }

    /**
     * Attach the dist's modules and site URL (read lazily, on first use).
     *
     * @param Closure(): array<string, array{version: string, root: string}> $modules Alias => version + webassets folder
     * @param Closure(): string $siteUrl The dist's site URL
     *
     * @return $this
     */
    public function bind(Closure $modules, Closure $siteUrl): self
    {
        $this->modules = $modules;
        $this->siteUrl = $siteUrl;
        $this->roots = null;

        return $this;
    }

    /**
     * The URL of a module's asset, with `?v=<content hash>` when the file exists.
     *
     * @param string $alias The module alias
     * @param string $file Path below the module's webassets folder
     *
     * @return string
     *
     * @throws InvalidArgumentException When no running module has the alias
     */
    public function url(string $alias, string $file): string
    {
        $module = $this->roots()[$alias] ?? null;
        if (null === $module) {
            throw new InvalidArgumentException("No running module with alias '{$alias}' has a webassets folder.");
        }
        $file = self::cleanPath($file) ?? \trim($file, '/');
        $url = $this->plainUrl($alias, $module['version'], $file);
        $hash = $module['manifest']->hash($file);

        return null === $hash ? $url : $url . '?v=' . $hash;
    }

    /**
     * The importmap: every .js/.mjs file of every module, from its plain URL
     * (what a relative `import` resolves to) to its hashed URL, plus the
     * configured bare specifiers. Every import of a file then loads one URL.
     *
     * @return array{imports: array<string, string>}
     */
    public function importMap(): array
    {
        $imports = [];
        $roots = $this->roots();
        \ksort($roots, SORT_STRING);
        foreach ($roots as $alias => $module) {
            foreach ($module['manifest']->files(self::SCRIPT_EXTENSIONS) as $file) {
                $imports[$this->plainUrl($alias, $module['version'], $file)] = $this->url($alias, $file);
            }
        }
        foreach ($this->imports as $specifier => [$alias, $file]) {
            $imports[$specifier] = $this->url($alias, $file);
        }

        return ['imports' => $imports];
    }

    /**
     * The importmap as JSON that is safe inside `<script>` (`<`, `>`, `&` escaped).
     *
     * @return string
     */
    public function importMapJson(): string
    {
        $imports = $this->importMap()['imports'];

        return \json_encode(
            ['imports' => $imports ?: new stdClass()],
            JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * The CSP source for the inline importmap, for a policy that lists hashes
     * instead of a nonce: `'sha256-…'` (quotes included).
     *
     * @return string
     */
    public function importMapHash(): string
    {
        return "'sha256-" . \base64_encode(\hash('sha256', $this->importMapJson(), true)) . "'";
    }

    /**
     * `<script type="importmap">` with the request's CSP nonce (RZ-10).
     *
     * @param string|null $nonce Null = no nonce attribute
     *
     * @return string
     */
    public function importMapTag(?string $nonce): string
    {
        $attribute = null === $nonce ? '' : ' nonce="' . \htmlspecialchars($nonce, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';

        return '<script type="importmap"' . $attribute . '>' . $this->importMapJson() . '</script>';
    }

    /**
     * @return bool Whether PHP answers /webassets/ requests for this dist
     */
    public function isServing(): bool
    {
        return $this->serve;
    }

    /**
     * Resolve `/webassets/{alias}/{version}/{file}` (the dist-relative URL
     * path, still URL-encoded) to a module file. The version must be the
     * running one.
     *
     * @param string $urlQuery The dist-relative URL path
     *
     * @return array{path: string, hash: string}|null Null when not serving or not a servable file
     */
    public function match(string $urlQuery): ?array
    {
        if (!$this->serve || !\preg_match('~^/webassets/([^/]+)/([^/]+)/(.+)$~', \rtrim($urlQuery, '/'), $m)) {
            return null;
        }
        $module = $this->roots()[\rawurldecode($m[1])] ?? null;
        if (null === $module || $module['version'] !== \rawurldecode($m[2])) {
            return null;
        }
        $path = StaticFile::resolve($module['manifest']->root(), $m[3]);

        return null === $path ? null : ['path' => $path, 'hash' => AssetManifest::hashPath($path)];
    }

    /**
     * Send the asset for a GET/HEAD request when {@see match()} finds one.
     *
     * @param string $urlQuery The dist-relative URL path
     * @param array<string, mixed>|null $server Null = $_SERVER
     * @param array<string, mixed>|null $query Null = $_GET
     *
     * @return bool True when the response was sent
     */
    public function serve(string $urlQuery, ?array $server = null, ?array $query = null): bool
    {
        $hit = $this->match($urlQuery);
        if (null === $hit) {
            return false;
        }
        $server ??= $_SERVER;
        if (!\in_array(\strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET')), ['GET', 'HEAD'], true)) {
            return false;
        }
        $version = ($query ?? $_GET)['v'] ?? null;
        (new StaticFile($hit['path'], $hit['hash']))->send($server, $version === $hit['hash'], $this->immutableMaxAge, $this->maxAge);

        return true;
    }

    /**
     * @return array<string, array{version: string, manifest: AssetManifest}>
     */
    private function roots(): array
    {
        if (null === $this->roots) {
            $this->roots = [];
            foreach (null === $this->modules ? [] : ($this->modules)() as $alias => $module) {
                $this->roots[$alias] = ['version' => $module['version'], 'manifest' => new AssetManifest($module['root'])];
            }
        }

        return $this->roots;
    }

    private function plainUrl(string $alias, string $version, string $file): string
    {
        $segments = \array_map('rawurlencode', \array_merge(['webassets', $alias, $version], \explode('/', $file)));

        return $this->base() . \implode('/', $segments);
    }

    private function base(): string
    {
        if (null !== $this->baseUrl) {
            return $this->baseUrl;
        }
        $path = (string) \parse_url(null === $this->siteUrl ? '' : ($this->siteUrl)(), PHP_URL_PATH);
        $path = \trim($path, '/');

        return '' === $path ? '/' : '/' . $path . '/';
    }
}
