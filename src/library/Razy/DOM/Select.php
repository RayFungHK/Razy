<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * Defines the Select class for the Razy DOM Builder. Provides a fluent
 * interface for constructing HTML `<select>` elements with `<option>`
 * children, supporting bulk option creation and selected value management.
 *
 *
 * @license MIT
 */

namespace Razy\DOM;

use InvalidArgumentException;
use Razy\DOM;

/**
 * Represents an HTML `<select>` element in the DOM Builder.
 *
 * Extends the base DOM class to provide methods for adding `<option>`
 * children, applying options from arrays with optional conversion callbacks,
 * managing selected values, and toggling the `multiple` attribute.
 *
 * @class Select
 */
class Select extends DOM
{
    /**
     * Select constructor.
     */
    public function __construct(string $id = '')
    {
        parent::__construct('', $id);
        $this->setTag('select');
    }

    /**
     * Apply a bulk of options by given array.
     *
     * @param array $dataset
     * @param callable|null $convertor
     *
     * @return $this
     *
     * @throws InvalidArgumentException
     */
    public function applyOptions(array $dataset, ?callable $convertor = null): self
    {
        foreach ($dataset as $key => $value) {
            $option = $this->addOption();
            if ($convertor) {
                \call_user_func($convertor(...), $option, $key, $value);
            } else {
                if (\is_string($value)) {
                    $option->setText($value)->setAttribute('value', $key);
                } else {
                    throw new InvalidArgumentException('The option value must be a string');
                }
            }
        }

        return $this;
    }

    /**
     * Add and append an option DOM.
     *
     * @param string $label
     * @param string $value
     *
     * @return DOM
     *
     * @throws Error
     */
    public function addOption(string $label = '', string $value = ''): DOM
    {
        $option = new DOM();
        $option->setTag('option')->setText($label)->setAttribute('value', $value);
        $this->append($option);

        return $option;
    }

    /**
     * Get the value.
     *
     * @return mixed The value of the control
     */
    public function getValue(): mixed
    {
        // Scan child option nodes for the one with a 'selected' attribute
        foreach ($this->nodes as $node) {
            if ($node instanceof DOM) {
                if ($node->hasAttribute('selected')) {
                    return $node->getAttribute('selected');
                }
            }
        }

        return null;
    }

    /**
     * Enable or disable multiple attribute.
     *
     * @param bool $enable
     *
     * @return $this
     *
     * @throws Error
     */
    public function isMultiple(bool $enable): self
    {
        if ($enable) {
            $this->setAttribute('multiple', 'multiple');
        } else {
            $this->removeAttribute('multiple');
        }

        return $this;
    }

    /**
     * Set the value.
     *
     * @param string $value The value of the control
     *
     * @return DOM Chainable
     *
     * @throws Error
     */
    public function setValue(string $value): DOM
    {
        // Mark the matching option as selected and deselect all others
        foreach ($this->nodes as $node) {
            if ($node instanceof DOM) {
                if ($node->getAttribute('value') == $value) {
                    $node->setAttribute('selected', 'selected');
                } else {
                    $node->removeAttribute('selected');
                }
            }
        }

        return $this;
    }
}
