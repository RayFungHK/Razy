<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license MIT
 */

namespace Razy\View;

use InvalidArgumentException;

/**
 * RZ-29: a UI fixture — one piece of UI with injected values, in named states,
 * rendered standalone by `php Razy.phar ui render` for pixel goldens.
 *
 * A `*.fixture.php` file returns one:
 *
 *     return Fixture::template('card.tpl')
 *         ->themes('light', 'dark')
 *         ->widths(1280, 360)
 *         ->assets(css: ['../dist/app.css'])
 *         ->base(['title' => 'Members'])
 *         ->state('default', ['rows' => $rows])
 *         ->state('empty', ['rows' => []])
 *         ->state('zh', ['title' => '成員'], lang: 'zh-HK');
 *
 * RZ-28: a `.rzx` component works the same way; its props are the values:
 *
 *     return Fixture::component('Kit.ManagementList')     // Kit = this folder
 *         ->namespaces(['App' => '../app/rzx'])            // other components it uses
 *         ->vocabulary('vocabulary.json')
 *         ->rzxFunction('t', fn (string $k): string => $k)
 *         ->base(['label' => 'Members', 'columns' => […]])
 *         ->state('empty', ['rows' => []])
 *         ->fill('toolbar-end', '<x-button label="Add"></x-button>');
 *
 * Paths are relative to the fixture file. Widths are only read by the screenshot
 * harness; the HTML is the same at every width. Generic: no element library or
 * application names here.
 */
final class Fixture
{
    private ?string $name = null;

    private string $file = '';

    /** @var list<string> */
    private array $themes = ['light'];

    /** @var list<int> */
    private array $widths = [1280];

    /** @var array<string, mixed> */
    private array $base = [];

    /** @var array<string, array{values: array<string, mixed>, lang: ?string}> */
    private array $states = [];

    /** @var list<string> */
    private array $css = [];

    /** @var list<string> */
    private array $js = [];

    private string $lang = 'en';

    private ?string $layouts = null;

    /** @var array<string, string> .rzx namespace => directory (relative) */
    private array $namespaces = [];

    private ?string $vocabulary = null;

    /** @var array<string, string> slot => trusted fixture HTML */
    private array $fills = [];

    /** @var array<string, callable> */
    private array $functions = [];

    private ?string $assetAlias = null;

    private ?string $webassets = null;

    private function __construct(private readonly ?string $template, private readonly ?string $component = null)
    {
    }

    /**
     * RZ-28: a fixture for a registered `.rzx` component. Its namespace maps to
     * the fixture's folder unless namespaces() says otherwise.
     */
    public static function component(string $name): self
    {
        if (!\Razy\Rzx\Names::isComponent($name)) {
            throw new InvalidArgumentException("Invalid component name '{$name}' (PascalCase, dotted: Kit.Card).");
        }

        return new self(null, $name);
    }

    /**
     * A fixture for a `.tpl` file (relative to the fixture file).
     */
    public static function template(string $path): self
    {
        if ('' === \trim($path)) {
            throw new InvalidArgumentException('Fixture::template() needs a template path.');
        }

        return new self(\trim($path));
    }

    /**
     * @param array<int, string> $names
     *
     * @return list<string>
     */
    private static function names(array $names, string $what): array
    {
        foreach ($names as $name) {
            if (!\preg_match('/^[a-z0-9][a-z0-9_-]*$/i', $name)) {
                throw new InvalidArgumentException("Invalid fixture {$what} name '{$name}'.");
            }
        }

        return \array_values(\array_unique($names));
    }

    /**
     * Directories of the `.rzx` namespaces the component uses (relative to the fixture).
     *
     * @param array<string, string> $map namespace ('' for bare names) => directory
     */
    public function namespaces(array $map): self
    {
        foreach ($map as $namespace => $dir) {
            if ('' !== $namespace && !\Razy\Rzx\Names::isComponent((string) $namespace)) {
                throw new InvalidArgumentException("Invalid .rzx namespace '{$namespace}'.");
            }
            $this->namespaces[(string) $namespace] = $dir;
        }

        return $this;
    }

    /** A vocabulary JSON file the component is compiled against. */
    public function vocabulary(string $path): self
    {
        $this->vocabulary = $path;

        return $this;
    }

    /**
     * Fill a slot with fixed markup. The markup is fixture code, printed as written.
     */
    public function fill(string $slot, string $html): self
    {
        $this->fills[$slot] = $html;

        return $this;
    }

    /**
     * The module whose web assets the component's `asset('js/x.js')` resolves
     * to: its alias and its `webassets/` folder (relative to the fixture;
     * default `../webassets`, next to a module's `rzx/` folder).
     */
    public function module(string $alias, ?string $webassets = null): self
    {
        if (!\preg_match('/^[A-Za-z0-9_.-]+$/', $alias)) {
            throw new InvalidArgumentException("Invalid module alias '{$alias}'.");
        }
        $this->assetAlias = $alias;
        $this->webassets = $webassets;

        return $this;
    }

    public function getModuleAlias(): ?string
    {
        return $this->assetAlias;
    }

    public function getWebassetsPath(): ?string
    {
        return null === $this->assetAlias ? null : $this->path($this->webassets ?? '../webassets');
    }

    /** An expression function the component calls (an app's `t`, for example). */
    public function rzxFunction(string $name, callable $function): self
    {
        $this->functions[$name] = $function;

        return $this;
    }

    /**
     * Name used by `ui list` / `ui render`; default: the file name without `.fixture.php`.
     */
    public function name(string $name): self
    {
        if (!\preg_match('/^[A-Za-z][\w.-]*$/', $name)) {
            throw new InvalidArgumentException("Invalid fixture name '{$name}'.");
        }
        $this->name = $name;

        return $this;
    }

    public function themes(string ...$themes): self
    {
        $this->themes = self::names($themes, 'theme');

        return $this;
    }

    public function widths(int ...$widths): self
    {
        foreach ($widths as $width) {
            if ($width < 200 || $width > 4000) {
                throw new InvalidArgumentException("Fixture width {$width} is out of range (200-4000).");
            }
        }
        $this->widths = \array_values(\array_unique($widths)) ?: [1280];

        return $this;
    }

    /**
     * Values every state starts from.
     *
     * @param array<string, mixed> $values
     */
    public function base(array $values): self
    {
        $this->base = $values;

        return $this;
    }

    /**
     * A named state: base values overlaid with $values (top-level keys replace).
     *
     * @param array<string, mixed> $values
     */
    public function state(string $name, array $values = [], ?string $lang = null): self
    {
        self::names([$name], 'state');
        $this->states[$name] = ['values' => $values, 'lang' => $lang];

        return $this;
    }

    /**
     * Local stylesheets and module scripts the HTML loads. Remote URLs are refused.
     *
     * @param list<string> $css
     * @param list<string> $js
     */
    public function assets(array $css = [], array $js = []): self
    {
        foreach ([...$css, ...$js] as $path) {
            if (\preg_match('#^(?:[a-z][a-z0-9+.-]*:)?//#i', $path)) {
                throw new InvalidArgumentException("Fixture assets must be local files, not '{$path}'.");
            }
        }
        $this->css = $css;
        $this->js = $js;

        return $this;
    }

    /**
     * Default `lang` of the page.
     */
    public function lang(string $lang): self
    {
        $this->lang = $lang;

        return $this;
    }

    /**
     * Folder that `@layout/…` includes resolve in (relative to the fixture file).
     */
    public function layouts(string $dir): self
    {
        $this->layouts = $dir;

        return $this;
    }

    /**
     * @internal set by the loader
     */
    public function withFile(string $file): self
    {
        $this->file = $file;

        return $this;
    }

    public function getName(): string
    {
        return $this->name ?? (string) \preg_replace('/\.fixture\.php$/', '', \basename($this->file));
    }

    public function getFile(): string
    {
        return $this->file;
    }

    public function getTemplatePath(): string
    {
        if (null === $this->template) {
            throw new InvalidArgumentException("Fixture '{$this->getName()}' is for the component {$this->component}, not a template.");
        }

        return $this->path($this->template);
    }

    /** The `.rzx` component name, or null for a template fixture. */
    public function getComponent(): ?string
    {
        return $this->component;
    }

    /**
     * Absolute directory per `.rzx` namespace; the component's own namespace
     * defaults to the fixture's folder.
     *
     * @return array<string, string>
     */
    public function getNamespaces(): array
    {
        $map = \array_map($this->path(...), $this->namespaces);
        if (null !== $this->component) {
            $dot = \strrpos($this->component, '.');
            $own = false === $dot ? '' : \substr($this->component, 0, $dot);
            $map[$own] ??= \rtrim(\dirname($this->file), '/\\');
        }

        return $map;
    }

    public function getVocabularyPath(): ?string
    {
        return null === $this->vocabulary ? null : $this->path($this->vocabulary);
    }

    /** @return array<string, string> */
    public function getFills(): array
    {
        return $this->fills;
    }

    /** @return array<string, callable> */
    public function getFunctions(): array
    {
        return $this->functions;
    }

    public function getLayoutsPath(): ?string
    {
        return null === $this->layouts ? null : $this->path($this->layouts);
    }

    /** @return list<string> */
    public function getThemes(): array
    {
        return $this->themes;
    }

    /** @return list<int> */
    public function getWidths(): array
    {
        return $this->widths;
    }

    /**
     * State names; a fixture without states has one, `default`.
     *
     * @return list<string>
     */
    public function getStates(): array
    {
        return $this->states ? \array_keys($this->states) : ['default'];
    }

    /**
     * The values of one state (base overlaid with the state).
     *
     * @return array<string, mixed>
     */
    public function valuesOf(string $state): array
    {
        if ($this->states && !isset($this->states[$state])) {
            throw new InvalidArgumentException("Fixture '{$this->getName()}' has no state '{$state}'.");
        }

        return \array_replace($this->base, $this->states[$state]['values'] ?? []);
    }

    public function langOf(string $state): string
    {
        return $this->states[$state]['lang'] ?? $this->lang;
    }

    /** @return list<string> Absolute paths */
    public function getCss(): array
    {
        return \array_map($this->path(...), $this->css);
    }

    /** @return list<string> Absolute paths */
    public function getJs(): array
    {
        return \array_map($this->path(...), $this->js);
    }

    private function path(string $relative): string
    {
        if (\str_starts_with($relative, '/') || 1 === \preg_match('/^[A-Za-z]:[\\\\\/]/', $relative)) {
            return $relative;
        }

        return \rtrim(\dirname($this->file), '/\\') . '/' . $relative;
    }
}
