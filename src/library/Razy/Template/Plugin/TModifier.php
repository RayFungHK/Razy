<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * Defines the TModifier base class for template modifier plugins. Modifiers
 * transform parameter values inline via the `->modifier:arg` syntax in
 * template tags (e.g., `{$name->trim->upper}`).
 *
 *
 * @license MIT
 */

namespace Razy\Template\Plugin;

use Razy\Controller;

/**
 * Base class for template modifier plugins.
 *
 * Modifiers are applied to parameter values in template tags using the arrow
 * syntax `{$var->modifier:arg1:arg2}`. Subclasses override `process()` to
 * implement custom value transformation. The first argument is always the
 * current value; additional colon-separated arguments follow.
 *
 * @class TModifier
 */
class TModifier
{
    /** @var Controller|null The bound controller instance, if any */
    protected ?Controller $controller = null;

    /** @var string The registered name of this modifier plugin */
    private string $name;

    /**
     * Bind a Controller instance to this plugin for module context access.
     *
     * @param Controller $entity The controller to bind
     *
     * @return static Chainable
     */
    final public function bind(Controller $entity): static
    {
        $this->controller = $entity;

        return $this;
    }

    /**
     * Modify the value.
     *
     * @param mixed $value The value to modify
     * @param string $paramText The well-formatted modifier's parameter string
     *
     * @return mixed
     */
    final public function modify(mixed $value, string $paramText = ''): mixed
    {
        $arguments = [$value];

        // PREG_SET_ORDER: one row per ':param' token. Row layout: [1] bare word,
        // [2] number-or-quoted-with-quotes, [4] quoted content (quotes stripped).
        // (A pre-fix version iterated the PATTERN-ORDER matrix, treating group
        // indices as argument indices — every modifier argument silently arrived
        // as '', so parameterised modifiers always ran on defaults. Pinned by
        // tests/TModifierParamParsingTest.)
        \preg_match_all('/:(?:(\w+)|(-?\d+(?:\.\d+)?|(?<q>[\'"])((?:\.(*SKIP)|(?!\k<q>).)*)\k<q>))/', $paramText, $matches, \PREG_SET_ORDER);

        foreach ($matches as $match) {
            if (($match[2] ?? '') !== '' && ("'" === $match[2][0] || '"' === $match[2][0])) {
                $arguments[] = $match[4] ?? '';
            } else {
                $arguments[] = (($match[1] ?? '') !== '' ? $match[1] : ($match[2] ?? ''));
            }
        }

        return \call_user_func_array([$this, 'process'], $arguments);
    }

    /**
     * Get the registered name of this modifier plugin.
     *
     * @return string The plugin name
     */
    final public function getName(): string
    {
        return $this->name;
    }

    /**
     * Set the registered name of this modifier plugin.
     *
     * @param string $name The plugin name
     *
     * @return static Chainable
     */
    final public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    /**
     * Process the modifier and transform the given value.
     *
     * Override this method in subclasses to implement custom transformation.
     *
     * @param mixed $value The current value to transform
     * @param string ...$args Additional colon-separated arguments from the modifier syntax
     *
     * @return string|null The transformed value
     */
    protected function process(mixed $value, string ...$args): ?string
    {
        return '';
    }
}
