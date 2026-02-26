<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * Provides shared parameter assignment and binding logic for all Template
 * scope levels (Template, Source, Block, Entity).
 *
 * @package Razy
 *
 * @license MIT
 */

namespace Razy\Template;

use Closure;
use Razy\Exception\TemplateException;

/**
 * Trait ParameterBagTrait.
 *
 * Extracts the common assign() and bind() implementations shared across
 * Template, Source, Block, and Entity. All four classes previously had
 * near-identical code for these methods.
 *
 * Classes using this trait MUST declare a `private array $parameters = [];` property.
 * Classes that need post-assignment side effects (e.g., cache invalidation)
 * should override `onParameterAssigned()`.
 */
trait ParameterBagTrait
{
    /**
     * Assign one or more parameter values.
     *
     * When an array is passed, each key-value pair is recursively assigned.
     * When a Closure is passed as value, it receives the current value and
     * the result replaces it.
     *
     * @param mixed $parameter The parameter name, or an associative array of name => value pairs
     * @param mixed|null $value The parameter value (ignored when $parameter is an array)
     *
     * @return static Chainable
     *
     * @throws Error If $parameter is neither string nor array
     */
    public function assign(mixed $parameter, mixed $value = null): static
    {
        if (\is_array($parameter)) {
            foreach ($parameter as $index => $value) {
                $this->assign($index, $value);
            }
        } elseif (\is_string($parameter)) {
            if ($value instanceof Closure) {
                // If the value is closure, pass the current value to closure
                $this->parameters[$parameter] = $value($this->parameters[$parameter] ?? null);
            } else {
                $this->parameters[$parameter] = $value;
            }
            $this->onParameterAssigned($parameter);
        } else {
            throw new TemplateException('Invalid parameter name');
        }

        return $this;
    }

    /**
     * Bind a parameter by reference.
     *
     * Unlike assign(), the value is not copied — a reference pointer is stored.
     * The actual value is not resolved until render time, so changes to the
     * original variable after binding will be reflected in the output.
     *
     * @param string $parameter The parameter name
     * @param mixed $value The variable to bind by reference
     *
     * @return static Chainable
     */
    public function bind(string $parameter, mixed &$value): static
    {
        $this->parameters[$parameter] = &$value;

        return $this;
    }

    /**
     * Hook called after a parameter is assigned via assign().
     * Override in classes that need post-assignment side effects
     * (e.g., Entity cache invalidation).
     *
     * @param string $parameter The parameter name that was assigned
     */
    protected function onParameterAssigned(string $parameter): void
    {
        // Default: no-op. Override in subclasses as needed.
    }
}
