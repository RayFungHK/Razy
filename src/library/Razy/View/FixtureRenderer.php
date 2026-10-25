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

use Razy\Exception\TemplateException;
use Razy\Template;
use Razy\Template\IncludeResolver;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;
use UnexpectedValueException;

/**
 * RZ-29: finds `*.fixture.php` files and renders their states to standalone HTML.
 *
 * Each state × theme renders the fixture's template with the state's values into
 * a built-in frame (`src/asset/ui/frame.tpl`: doctype, `<html lang data-theme>`,
 * the usual anchors, the page at `content`). Rendering goes through the same
 * anchors as a site page (RZ-27), so {@push}, the CSP nonce and dedupe behave as
 * in production. The output must not load anything from the network.
 */
final class FixtureRenderer
{
    private const FRAME = __DIR__ . '/../../../asset/ui/frame.tpl';

    /**
     * Every `*.fixture.php` under the roots (vendor/, node_modules/ and dot folders skipped).
     *
     * @param list<string> $roots
     *
     * @return list<string> Sorted absolute paths
     */
    public static function discover(array $roots): array
    {
        $found = [];
        foreach ($roots as $root) {
            if (\is_file($root) && \str_ends_with($root, '.fixture.php')) {
                $found[] = (string) \realpath($root);
                continue;
            }
            if (!\is_dir($root)) {
                throw new UnexpectedValueException("Fixture root '{$root}' is not a directory.");
            }
            // Prune vendor/, node_modules/ and dot folders before descending into them (only
            // below the root: the root itself may sit under a dot folder, e.g. ~/.t3/…).
            $tree = new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
                static fn (SplFileInfo $file): bool => !$file->isDir()
                    || !\preg_match('/^(?:vendor|node_modules|\..+)$/', $file->getFilename()),
            );
            foreach (new RecursiveIteratorIterator($tree) as $file) {
                $path = $file->getPathname();
                if (\str_ends_with($path, '.fixture.php')) {
                    $found[] = (string) \realpath($path);
                }
            }
        }
        $found = \array_values(\array_unique($found));
        \sort($found);

        return $found;
    }

    /**
     * Load one fixture file.
     *
     * @throws UnexpectedValueException When the file does not return a Fixture
     */
    public static function load(string $file): Fixture
    {
        $path = \realpath($file);
        if (false === $path || !\is_file($path)) {
            throw new UnexpectedValueException("Fixture file '{$file}' does not exist.");
        }
        $fixture = (static fn (string $__file) => require $__file)($path);
        if (!$fixture instanceof Fixture) {
            throw new UnexpectedValueException("'{$file}' must return a Razy\\View\\Fixture.");
        }

        return $fixture->withFile($path);
    }

    /**
     * Find a fixture by name among the roots.
     *
     * @param list<string> $roots
     */
    public static function find(string $name, array $roots): Fixture
    {
        foreach (self::discover($roots) as $file) {
            $fixture = self::load($file);
            if ($fixture->getName() === $name) {
                return $fixture;
            }
        }

        throw new UnexpectedValueException("No fixture named '{$name}' under " . \implode(', ', $roots) . '.');
    }

    /**
     * Describe a fixture for `ui list --json` (what the screenshot harness reads).
     *
     * @return array{name: string, file: string, states: list<string>, themes: list<string>, widths: list<int>}
     */
    public static function describe(Fixture $fixture): array
    {
        return [
            'name' => $fixture->getName(),
            'file' => $fixture->getFile(),
            'states' => $fixture->getStates(),
            'themes' => $fixture->getThemes(),
            'widths' => $fixture->getWidths(),
        ];
    }

    /**
     * Where an asset is copied, relative to the HTML: `assets/<kind><n>-<basename>`.
     */
    private static function assetName(string $kind, int $index, string $path): string
    {
        return 'assets/' . $kind . $index . '-' . \basename($path);
    }

    /**
     * Render one state in one theme to a standalone HTML document.
     *
     * @throws TemplateException When the HTML would load something over the network
     * @throws Throwable
     */
    public function render(Fixture $fixture, string $state, string $theme): string
    {
        if (!\in_array($theme, $fixture->getThemes(), true)) {
            throw new UnexpectedValueException("Fixture '{$fixture->getName()}' has no theme '{$theme}'.");
        }
        $values = $fixture->valuesOf($state);

        $config = null === $fixture->getLayoutsPath() ? [] : ['templates' => ['layout_path' => $fixture->getLayoutsPath()]];
        $template = (new Template())
            ->setAutoEscape(true)
            ->setIncludeResolver(IncludeResolver::fromConfig($config, 'ui', \dirname($fixture->getFile())));
        $template->assign([
            'ui_lang' => $fixture->langOf($state),
            'ui_theme' => $theme,
            'ui_title' => $fixture->getName(),
            'ui_state' => $state,
        ]);

        $anchors = $template->anchors();
        foreach ($fixture->getCss() as $index => $css) {
            $anchors->push('head.styles', '<link rel="stylesheet" href="' . \htmlspecialchars(self::assetName('css', $index, $css), ENT_QUOTES) . '">', null, $index);
        }
        foreach ($fixture->getJs() as $index => $js) {
            $anchors->push('body.scripts', '<script type="module" src="' . \htmlspecialchars(self::assetName('js', $index, $js), ENT_QUOTES) . '"></script>', null, $index);
        }

        $template->beginRender();
        try {
            $page = $template->load($fixture->getTemplatePath());
            $page->assign($values);
            $anchors->setContent($page->output());
            $html = $template->load(self::FRAME)->output();
        } catch (Throwable $e) {
            $template->endRender('');
            throw $e;
        }
        $html = $template->endRender($html);

        if ($unresolved = $anchors->unresolved()) {
            throw new TemplateException("Fixture '{$fixture->getName()}' pushed to anchors the frame does not have: " . \implode(', ', \array_keys($unresolved)) . '.');
        }
        if (\preg_match('#\b(?:src|href|action|poster|data)\s*=\s*["\']?\s*(?:[a-z][a-z0-9+.-]*:)?//#i', $html, $remote)) {
            throw new TemplateException("Fixture '{$fixture->getName()}' state '{$state}' loads something over the network ({$remote[0]}…); fixtures must be self-contained.");
        }

        return $html;
    }

    /**
     * Render states × themes into `$out/<name>/<state>-<theme>.html` and copy the assets.
     *
     * @param list<string>|null $states Null = every state
     * @param list<string>|null $themes Null = every theme
     *
     * @return list<string> Written HTML files
     *
     * @throws Throwable
     */
    public function write(Fixture $fixture, string $out, ?array $states = null, ?array $themes = null): array
    {
        $dir = \rtrim($out, '/\\') . '/' . $fixture->getName();
        if (!\is_dir($dir . '/assets') && !\mkdir($dir . '/assets', 0o755, true) && !\is_dir($dir . '/assets')) {
            throw new UnexpectedValueException("Cannot create '{$dir}/assets'.");
        }
        foreach (['css' => $fixture->getCss(), 'js' => $fixture->getJs()] as $kind => $list) {
            foreach ($list as $index => $asset) {
                if (!\is_file($asset)) {
                    throw new UnexpectedValueException("Fixture asset '{$asset}' does not exist.");
                }
                \copy($asset, $dir . '/' . self::assetName($kind, $index, $asset));
            }
        }

        $written = [];
        foreach ($states ?? $fixture->getStates() as $state) {
            foreach ($themes ?? $fixture->getThemes() as $theme) {
                $file = $dir . '/' . $state . '-' . $theme . '.html';
                \file_put_contents($file, $this->render($fixture, $state, $theme));
                $written[] = $file;
            }
        }

        return $written;
    }
}
