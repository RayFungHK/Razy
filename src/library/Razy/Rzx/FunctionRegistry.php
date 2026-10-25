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

use InvalidArgumentException;
use Razy\Rzx\Runtime\RenderError;

/**
 * The functions an expression may call. Unknown names are compile errors.
 *
 * `count`, `html` and `json` are built in (the compiler emits them inline).
 * Everything else is registered by the host: Razy's asset, i18n and modifier
 * functions (RZ-28c), or an app's own. A registered function receives
 * plain values and returns a value that is then escaped for its context
 * like any other; it cannot emit raw HTML unless it returns SafeHtml and the
 * call sits inside `html(…)`.
 */
final class FunctionRegistry
{
    public const BUILTINS = ['count', 'html', 'json'];

    /** @var array<string, callable> */
    private array $functions = [];

    public function register(string $name, callable $function): self
    {
        if (!\preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
            throw new InvalidArgumentException('Invalid .rzx function name: ' . $name);
        }
        if (\in_array($name, self::BUILTINS, true) || \in_array($name, ['true', 'false', 'null'], true)) {
            throw new InvalidArgumentException('`' . $name . '` is reserved in .rzx expressions');
        }
        $this->functions[$name] = $function;

        return $this;
    }

    public function has(string $name): bool
    {
        return isset($this->functions[$name]) || \in_array($name, self::BUILTINS, true);
    }

    /**
     * Every callable name, built-ins first.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return [...self::BUILTINS, ...\array_map(\strval(...), \array_keys($this->functions))];
    }

    /**
     * Fingerprint of the registered names, part of every component's cache hash:
     * adding or removing a function recompiles the components that could call it.
     */
    public function signature(): string
    {
        $names = $this->names();
        \sort($names);

        return \hash('sha256', \implode(',', $names));
    }

    /**
     * @param list<mixed> $arguments
     */
    public function call(string $name, array $arguments): mixed
    {
        if (!isset($this->functions[$name])) {
            throw new RenderError('Unknown .rzx function `' . $name . '`');
        }

        return ($this->functions[$name])(...$arguments);
    }
}
