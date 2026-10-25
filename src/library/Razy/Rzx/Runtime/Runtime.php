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

namespace Razy\Rzx\Runtime;

use ArrayAccess;
use Closure;
use Countable;
use Razy\Rzx\FunctionRegistry;
use Razy\Rzx\SafeHtml;
use Throwable;
use Traversable;

/**
 * What compiled `.rzx` code calls at render time: value access, operators,
 * escaping, component calls, slots and anchors.
 *
 * One Runtime serves one render (one request). Compiled render closures are
 * stateless; everything per request lives here, so FrankenPHP worker mode
 * cannot leak state between requests.
 */
final class Runtime
{
    /** @var Closure(string): void */
    private Closure $warn;

    private int $depth = 0;

    /** @var array<string, Compiled> compiled file => component, to locate errors in any of them */
    private array $compiled = [];

    /**
     * @param bool $checkProps check props at each component boundary and loop keys (development)
     * @param (callable(string): void)|null $warn receives warnings (unsafe URL dropped, …); default error_log
     */
    public function __construct(
        private readonly ComponentLoader $loader,
        private readonly FunctionRegistry $functions = new FunctionRegistry(),
        private readonly ?AnchorSink $anchors = null,
        private readonly bool $checkProps = true,
        ?callable $warn = null,
    ) {
        $this->warn = $warn === null ? static function (string $message): void {
            \error_log('[rzx] ' . $message);
        } : Closure::fromCallable($warn);
    }

    /**
     * Render a component to a string.
     *
     * @param array<string, mixed> $props
     * @param array<string, Closure(): void|SafeHtml> $fills slot name => fill
     */
    public function render(string $name, array $props = [], array $fills = []): string
    {
        $level = \ob_get_level();
        \ob_start();

        try {
            $this->component($name, $props, new SlotBag($fills), '');

            return (string) \ob_get_clean();
        } catch (Throwable $e) {
            while (\ob_get_level() > $level) {
                \ob_end_clean();
            }

            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $props
     * @param string $site the use site (`File.rzx:12`), for error messages
     */
    public function component(string $name, array $props, SlotBag $slots, string $site): void
    {
        if ($this->depth >= 64) {
            throw new RenderError('Components are nested too deeply (limit 64) at ' . $name, $site);
        }
        $compiled = $this->loader->load($name);
        $this->compiled[$compiled->compiledFile] = $compiled;
        foreach ($compiled->props as $prop => $schema) {
            if (($props[$prop] ?? null) === null && $schema->hasDefault()) {
                $props[$prop] = $schema->defaultValue();
            }
            if ($this->checkProps && ($error = $schema->check($props[$prop] ?? null, $name . '.' . $prop)) !== null) {
                throw new RenderError('Invalid prop: ' . $error, $site);
            }
        }

        ++$this->depth;

        try {
            ($compiled->render)($props, $slots, $this);
        } catch (RenderError $e) {
            if ($e->rzxLocation !== '') {
                throw $e;
            }

            throw new RenderError($e->getMessage(), $this->locate($compiled, $e), $e);
        } catch (Throwable $e) {
            throw new RenderError($e->getMessage(), $this->locate($compiled, $e), $e);
        } finally {
            --$this->depth;
        }
    }

    public function get(mixed $base, string|int $key): mixed
    {
        if (\is_array($base)) {
            return $base[$key] ?? null;
        }
        if ($base instanceof ArrayAccess) {
            return $base->offsetExists($key) ? $base->offsetGet($key) : null;
        }
        if (\is_object($base)) {
            // Public properties only (this scope sees no private ones); never __get, never a method.
            $vars = \get_object_vars($base);

            return $vars[$key] ?? null;
        }

        return null;
    }

    public function key(mixed $value): string|int
    {
        return \is_int($value) ? $value : Escape::stringify($value);
    }

    public function truthy(mixed $value): bool
    {
        return !($value === null || $value === false || $value === '' || $value === 0 || $value === 0.0 || $value === []);
    }

    public function str(mixed $value): string
    {
        return Escape::stringify($value);
    }

    public function num(mixed $value): int|float
    {
        return match (true) {
            \is_int($value), \is_float($value) => $value,
            $value === null => 0,
            \is_string($value) && \is_numeric($value) => $value + 0,
            default => throw new RenderError('`+` and `-` need numbers, got ' . \get_debug_type($value)),
        };
    }

    public function eq(mixed $a, mixed $b): bool
    {
        if ((\is_int($a) || \is_float($a)) && (\is_int($b) || \is_float($b))) {
            return $a == $b;
        }

        return $a === $b;
    }

    public function cmp(mixed $a, string $operator, mixed $b): bool
    {
        $numbers = (\is_int($a) || \is_float($a)) && (\is_int($b) || \is_float($b));
        if (!$numbers && !(\is_string($a) && \is_string($b))) {
            throw new RenderError('`' . $operator . '` compares two numbers or two strings, got ' . \get_debug_type($a) . ' and ' . \get_debug_type($b));
        }

        return match ($operator) {
            '<' => $a < $b,
            '<=' => $a <= $b,
            '>' => $a > $b,
            default => $a >= $b,
        };
    }

    public function count(mixed $value): int
    {
        return match (true) {
            \is_array($value), $value instanceof Countable => \count($value),
            $value === null => 0,
            \is_string($value) => (int) \preg_match_all('/./su', $value),
            default => throw new RenderError('count() needs a list, got ' . \get_debug_type($value)),
        };
    }

    public function json(mixed $value): string
    {
        return Escape::json($value);
    }

    /**
     * @param list<mixed> $arguments
     */
    public function call(string $name, array $arguments): mixed
    {
        return $this->functions->call($name, $arguments);
    }

    /**
     * @return iterable<mixed>
     */
    public function iter(mixed $value): iterable
    {
        return match (true) {
            \is_array($value), $value instanceof Traversable => $value,
            $value === null => [],
            default => throw new RenderError('<for each> needs a list, got ' . \get_debug_type($value)),
        };
    }

    /**
     * Development check that a `<for key>` value is present and unique.
     *
     * @param array<string|int, true> $seen
     */
    public function loopKey(array &$seen, mixed $item, string $key, string $site): void
    {
        if (!$this->checkProps) {
            return;
        }
        $value = $this->get($item, $key);
        if (!\is_string($value) && !\is_int($value)) {
            throw new RenderError('<for key="' . $key . '"> needs a string or integer key, got ' . \get_debug_type($value), $site);
        }
        if (isset($seen[$value])) {
            throw new RenderError('<for key="' . $key . '"> has the duplicate key ' . \var_export($value, true), $site);
        }
        $seen[$value] = true;
    }

    public function text(mixed $value): string
    {
        return Escape::text($value);
    }

    public function safe(mixed $value): string
    {
        return Escape::safe($value);
    }

    public function attr(string $name, mixed $value, bool $resource = false): string
    {
        return Escape::attribute($name, $value, $this->warn, $resource);
    }

    public function jsonScript(mixed $value): string
    {
        return Escape::json($value);
    }

    /**
     * ` style="--a:1;--b:2"` from a hooks map. Names were checked at compile
     * time; a value that is not a plain CSS token is dropped with a warning.
     *
     * @param array<string, mixed> $hooks
     */
    public function hooks(array $hooks): string
    {
        $rules = [];
        foreach ($hooks as $name => $value) {
            $text = \is_int($value) || \is_float($value) ? (string) $value : (\is_string($value) ? \trim($value) : null);
            if ($text === null || !\preg_match('/^[A-Za-z0-9 .,%#()+\-\/*]*$/', $text) || \preg_match('/url\s*\(|expression|image-set|\/\*/i', $text)) {
                ($this->warn)('hooks value for ' . $name . ' dropped: not a plain CSS value');
                continue;
            }
            $rules[] = $name . ':' . $text;
        }

        return $rules === [] ? '' : ' style="' . Escape::html(\implode(';', $rules)) . '"';
    }

    /**
     * Render a closure to a string (the body of a `<push>`).
     *
     * @param Closure(): void $body
     */
    public function capture(Closure $body): string
    {
        $level = \ob_get_level();
        \ob_start();

        try {
            $body();

            return (string) \ob_get_clean();
        } finally {
            while (\ob_get_level() > $level) {
                \ob_end_clean();
            }
        }
    }

    public function push(string $anchor, string $html, ?string $key, int $order): void
    {
        if ($this->anchors === null) {
            throw new RenderError('<push to="' . $anchor . '"> needs a page layout; this render has no anchors');
        }
        $this->anchors->push($anchor, $html, $key, $order);
    }

    public function marker(string $name): string
    {
        if ($this->anchors === null) {
            throw new RenderError('<Anchor name="' . $name . '"> needs a page layout; this render has no anchors');
        }

        return $this->anchors->marker($name);
    }

    /**
     * Map an exception thrown inside compiled code back to its `.rzx` line:
     * the innermost frame in any compiled file. A fill runs inside its slot
     * owner's render but was compiled from the caller's file, so the error
     * points at the fill, not at the `<Slot>`.
     */
    private function locate(Compiled $owner, Throwable $e): string
    {
        $frames = [['file' => $e->getFile(), 'line' => $e->getLine()], ...$e->getTrace()];
        foreach ($frames as $frame) {
            $compiled = $this->compiled[$frame['file'] ?? ''] ?? null;
            if ($compiled !== null && isset($frame['line'])) {
                $line = $compiled->rzxLine((int) $frame['line']);
                if ($line !== null) {
                    return $compiled->source . ':' . $line;
                }
            }
        }

        return $owner->source;
    }
}
