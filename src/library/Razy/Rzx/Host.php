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
use FilesystemIterator;
use Razy\Exception\ConfigurationException;
use Razy\ModuleInfo;
use Razy\Rzx\Runtime\Runtime;
use Razy\Template;
use Razy\Util\PathUtil;
use Razy\View\Anchors;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

/**
 * One dist's `.rzx` setup: the component namespaces its modules declare,
 * their vocabularies, the expression functions, and the build cache.
 *
 * dist.php:
 *
 *     'rzx' => [
 *         'mode'           => 'dev',       // or 'compiled': never compile at request time
 *         'check_props'    => 'dev',       // true | false | 'dev' (= on in dev mode)
 *         'screen_profile' => 'screen',    // Profile for module view/ screens
 *         'layout_profile' => 'layout',    // Profile for an .rzx site layout
 *     ],
 *
 * A package declares components in package.php:
 * `'rzx' => ['namespace' => 'Kit', 'path' => 'rzx', 'vocabulary' => 'vocabulary.json', 'profile' => 'composition']`.
 * Profiles only apply when a registered vocabulary defines them.
 */
final class Host
{
    private static ?self $current = null;

    private ?CompileCache $cache = null;

    private ?DirectoryResolver $resolver = null;

    private ?Vocabulary $vocabulary = null;

    private readonly FunctionRegistry $functions;

    private readonly RazyFunctions $razy;

    /** @var array<string, string> profile name => who declared it (must exist once a vocabulary loads) */
    private array $explicitProfiles = [];

    /**
     * @param Closure(): iterable<ModuleInfo> $modules the dist's loaded modules (read when the first component compiles)
     * @param array<string, string> $explicitProfiles profile name => where it was declared (dist.php); must exist once a vocabulary loads
     */
    public function __construct(
        private readonly string $cacheDirectory,
        private readonly Closure $modules,
        private readonly string $mode = 'dev',
        private readonly bool $checkProps = true,
        private readonly ?string $screenProfile = 'screen',
        private readonly ?string $layoutProfile = 'layout',
        ?Closure $siteUrl = null,
        array $explicitProfiles = [],
    ) {
        $this->explicitProfiles = $explicitProfiles;
        $this->functions = new FunctionRegistry();
        $this->razy = new RazyFunctions($siteUrl ?? static fn (): string => '');
        $this->razy->register($this->functions);
    }

    /**
     * @param array<string, mixed> $config dist.php
     * @param Closure(): iterable<ModuleInfo> $modules
     *
     * @throws ConfigurationException
     */
    public static function fromConfig(array $config, string $distCode, string $dataFolder, Closure $modules, ?Closure $siteUrl = null): self
    {
        $rzx = $config['rzx'] ?? [];
        if (!\is_array($rzx)) {
            throw new ConfigurationException("dist.php 'rzx' must be an array for dist '{$distCode}'.");
        }
        $mode = $rzx['mode'] ?? 'dev';
        if (!\in_array($mode, CompileCache::MODES, true)) {
            throw new ConfigurationException("dist.php 'rzx.mode' must be 'dev' or 'compiled' for dist '{$distCode}'.");
        }
        $check = $rzx['check_props'] ?? 'dev';
        if (!\is_bool($check) && $check !== 'dev') {
            throw new ConfigurationException("dist.php 'rzx.check_props' must be true, false or 'dev' for dist '{$distCode}'.");
        }
        $profile = static function (string $key, string $default) use ($rzx, $distCode): ?string {
            $value = \array_key_exists($key, $rzx) ? $rzx[$key] : $default;
            if ($value !== null && (!\is_string($value) || $value === '')) {
                throw new ConfigurationException("dist.php 'rzx.{$key}' must be a profile name or null for dist '{$distCode}'.");
            }

            return $value;
        };

        return new self(
            PathUtil::append($dataFolder, 'compiled', 'rzx', $distCode === '' ? '_' : $distCode),
            $modules,
            $mode,
            $check === 'dev' ? $mode === 'dev' : $check,
            $profile('screen_profile', 'screen'),
            $profile('layout_profile', 'layout'),
            $siteUrl,
            \array_fill_keys(\array_values(\array_filter([
                \is_string($rzx['screen_profile'] ?? null) ? $rzx['screen_profile'] : null,
                \is_string($rzx['layout_profile'] ?? null) ? $rzx['layout_profile'] : null,
            ])), "dist.php 'rzx'"),
        );
    }

    /** The Host of the dist serving this request (null before a dist boots). */
    public static function current(): ?self
    {
        return self::$current;
    }

    public static function setCurrent(?self $host): void
    {
        self::$current = $host;
    }

    /**
     * Start a request: the dev freshness check runs again (worker mode would
     * otherwise keep the first request's view of the sources).
     */
    public function reset(): void
    {
        $this->cache = null;
    }

    /**
     * Expression functions. Register app functions (such as `t`) while booting,
     * before the first render; the set is part of every component's cache hash.
     */
    public function functions(): FunctionRegistry
    {
        return $this->functions;
    }

    public function cache(): CompileCache
    {
        return $this->cache ??= new CompileCache($this->resolver(), $this->cacheDirectory, $this->functions, $this->mode, $this->vocabulary());
    }

    /**
     * Render a screen (a module's `view/….rzx`) into the site layout, the way
     * Template::renderPage() does for `.tpl`: page first, then the layout
     * around it, then the anchor markers are replaced.
     *
     * @param array<string, mixed> $props
     * @param array<string, mixed> $layoutProps props of an `.rzx` layout (a `.tpl` layout gets them assigned)
     *
     * @throws Throwable
     */
    public function renderPage(Template $template, ?ModuleInfo $module, string $screen, array $props = [], array $layoutProps = []): string
    {
        $template->beginRender();

        try {
            $runtime = $this->runtime($template);
            $html = $this->razy->within($template, $module, fn (): string => $runtime->renderFile($screen, $props, [], $this->screenProfile));
            $layout = $template->getLayout();
            if ($layout !== null) {
                $path = $template->getIncludeResolver()->resolve($layout, '');
                if ($path === null) {
                    throw new \Razy\Exception\TemplateException("The site layout '{$layout}' was not found.");
                }
                $template->anchors()->setContent($html);
                $html = \str_ends_with($path, '.rzx')
                    ? $this->razy->within($template, null, fn (): string => $runtime->renderFile($path, $layoutProps, ['' => new SafeHtml($template->anchors()->marker(Anchors::CONTENT))], $this->layoutProfile))
                    : $template->load($path)->assign($layoutProps)->output();
            }
        } catch (Throwable $e) {
            $template->abortRender();

            throw $e;
        }

        return $template->endRender($html);
    }

    /**
     * Render one component to a string (the `{@rzx}` bridge for `.tpl` pages).
     *
     * @param array<string, mixed> $props
     */
    public function renderComponent(Template $template, ?ModuleInfo $module, string $name, array $props = []): string
    {
        return $this->razy->within($template, $module, fn (): string => $this->runtime($template)->render($name, $props));
    }

    public function runtime(Template $template): Runtime
    {
        return new Runtime($this->cache(), $this->functions, new TemplateAnchors($template->anchors()), $this->checkProps);
    }

    /**
     * Compile every registered component and every module screen; for deploy.
     * Throws the first CompileError.
     *
     * @return array<string, string> component name or file => hash
     */
    public function build(): array
    {
        // Deploy compiles even when requests run in 'compiled' mode.
        $cache = new CompileCache($this->resolver(), $this->cacheDirectory, $this->functions, 'dev', $this->vocabulary());
        $hashes = $cache->build($this->resolver()->all());
        foreach (($this->modules)() as $info) {
            $view = PathUtil::append($info->getPath(), 'view');
            foreach ($this->rzxFiles($view) as $file) {
                $hashes[$file] = $cache->loadFile($file, $this->screenProfile)->name;
            }
        }

        return $hashes;
    }

    /**
     * @return list<string>
     */
    private function rzxFiles(string $directory): array
    {
        if (!\is_dir($directory)) {
            return [];
        }
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && \str_ends_with($file->getPathname(), '.rzx')) {
                $files[] = \str_replace('\\', '/', $file->getPathname());
            }
        }
        \sort($files);

        return $files;
    }

    private function resolver(): DirectoryResolver
    {
        if ($this->resolver === null) {
            $this->load();
        }

        return $this->resolver ?? new DirectoryResolver();
    }

    private function vocabulary(): Vocabulary
    {
        if ($this->vocabulary === null) {
            $this->load();
        }

        return $this->vocabulary ?? Vocabulary::none();
    }

    /**
     * Read every module's package.php 'rzx' declaration.
     */
    private function load(): void
    {
        $resolver = new DirectoryResolver();
        $vocabularies = [];
        foreach (($this->modules)() as $info) {
            $rzx = $info->getRzx();
            if ($rzx === []) {
                continue;
            }
            if (isset($rzx['namespace'])) {
                $resolver->add($rzx['namespace'], PathUtil::append($info->getPath(), $rzx['path'] ?? 'rzx'), $rzx['profile'] ?? 'composition');
                if (isset($rzx['profile'])) {
                    $this->explicitProfiles[$rzx['profile']] = "package.php 'rzx.profile' of " . $info->getCode();
                }
            }
            if (isset($rzx['vocabulary'])) {
                $vocabularies[] = Vocabulary::fromFile(PathUtil::append($info->getPath(), $rzx['vocabulary']));
            }
        }
        $vocabulary = Vocabulary::merge(...$vocabularies);
        // A profile someone named explicitly must exist: a typo would silently mean "no rules".
        foreach ($this->explicitProfiles as $name => $declaredBy) {
            if ($vocabulary->profile($name) === null) {
                $known = \array_keys($vocabulary->profiles);
                throw new ConfigurationException("Unknown .rzx profile '{$name}' in {$declaredBy}; registered profiles: " . ($known === [] ? 'none (no vocabulary defines any)' : \implode(', ', $known)) . '.');
            }
        }
        $this->resolver = $resolver;
        $this->vocabulary = $vocabulary;
    }
}
