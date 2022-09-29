<?php

/*
 * This file is part of Razy v0.4.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Razy\DOM;

use Closure;
use Razy\DOM;
use Razy\Error;

class Select extends DOM
{
    /**
     * Select constructor.
     */
    public function __construct(string $id = '')
    {
        parent::__construct($id);
        $this->setTag('select');
    }

    /**
     * Apply a bulk of options by given array.
     *
     * @param array        $dataset
     * @param Closure|null $convertor
     *
     * @return $this
     * @throws Error
     */
    public function applyOptions(array $dataset, Closure $convertor = null): self
    {
        foreach ($dataset as $key => $value) {
            $option = $this->addOption();
            if ($convertor) {
                call_user_func($convertor, $option, $key, $value);
            } else {
                if (is_string($value)) {
                    $option->setText($value)->setAttribute('value', $key);
                } else {
                    throw new Error('The option value must be a string');
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
    public function getValue()
    {
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
     * Enable or disable multiple attribute
     *
     * @param bool $enable
     *
     * @return $this
     * @throws Error
     */
    public function isMultiple(bool $enable): Select
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
     * @param mixed $value The value of the control
     *
     * @return self Chainable
     * @throws Error
     */
    public function setValue(string $value): DOM
    {
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
