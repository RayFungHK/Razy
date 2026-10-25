<?php

/**
 * CLI Command: ui (RZ-29).
 *
 * Render UI fixtures (`*.fixture.php`) to standalone HTML for pixel goldens.
 *
 * Usage:
 *   php Razy.phar ui list [<root>…] [--json]
 *   php Razy.phar ui render <name|file.fixture.php> [<state>] [--theme=<t>] [--root=<dir>]… [--out=<dir>]
 *
 * `ui list` finds fixtures under the roots (default: the current directory);
 * `--json` prints name, file, states, themes and widths for the screenshot harness.
 * `ui render` without --out prints one state in one theme (default: the first of
 * each) to stdout; with --out it writes `<out>/<name>/<state>-<theme>.html` for the
 * given state/theme (default: all) and copies the fixture's assets next to them.
 * There is no width option: width is the screenshot viewport, read from the
 * fixture's widths() by the harness. Exits 1 on any error.
 *
 * @license MIT
 */

namespace Razy;

use Razy\View\FixtureRenderer;
use Throwable;

return function (string $sub = '', ...$args) {
    $roots = [];
    $positional = [];
    $theme = null;
    $out = null;
    $json = false;
    foreach ($args as $arg) {
        if (\str_starts_with($arg, '--root=')) {
            $roots[] = \substr($arg, 7);
        } elseif (\str_starts_with($arg, '--theme=')) {
            $theme = \substr($arg, 8);
        } elseif (\str_starts_with($arg, '--out=')) {
            $out = \substr($arg, 6);
        } elseif ('--json' === $arg) {
            $json = true;
        } elseif (!\str_starts_with($arg, '-')) {
            $positional[] = $arg;
        }
    }

    $fail = function (string $message): never {
        \fwrite(STDERR, "[ui] {$message}\n");
        exit(1);
    };

    try {
        switch ($sub) {
            case 'list':
                $roots = $positional ?: ($roots ?: [(string) \getcwd()]);
                $list = \array_map(
                    static fn (string $file): array => FixtureRenderer::describe(FixtureRenderer::load($file)),
                    FixtureRenderer::discover($roots),
                );
                if ($json) {
                    echo \json_encode($list, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;

                    return true;
                }
                foreach ($list as $item) {
                    echo \sprintf(
                        "%-28s states: %-30s themes: %-12s widths: %-10s %s\n",
                        $item['name'],
                        \implode(',', $item['states']),
                        \implode(',', $item['themes']),
                        \implode(',', $item['widths']),
                        $item['file'],
                    );
                }
                if (!$list) {
                    echo "No fixtures under " . \implode(', ', $roots) . ".\n";
                }

                return true;

            case 'render':
                $target = $positional[0] ?? '';
                if ('' === $target) {
                    $fail('Usage: php Razy.phar ui render <name|file.fixture.php> [<state>] [--theme=<t>] [--root=<dir>] [--out=<dir>]');
                }
                $fixture = \is_file($target)
                    ? FixtureRenderer::load($target)
                    : FixtureRenderer::find($target, $roots ?: [(string) \getcwd()]);
                $state = $positional[1] ?? null;
                $renderer = new FixtureRenderer();

                if (null === $out) {
                    echo $renderer->render($fixture, $state ?? $fixture->getStates()[0], $theme ?? $fixture->getThemes()[0]);

                    return true;
                }
                $files = $renderer->write($fixture, $out, null === $state ? null : [$state], null === $theme ? null : [$theme]);
                foreach ($files as $file) {
                    echo $file, PHP_EOL;
                }

                return true;

            default:
                $fail("Unknown subcommand '{$sub}'. Use: ui list | ui render");
        }
    } catch (Throwable $e) {
        $fail($e->getMessage());
    }
};
