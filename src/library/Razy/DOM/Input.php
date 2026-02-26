<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * Defines the Input class for the Razy DOM Builder. Provides a fluent
 * interface for constructing HTML `<input>` elements with common attributes
 * such as type, name, value, and placeholder.
 *
 *
 * @license MIT
 */

namespace Razy\DOM;

use Razy\DOM;

/**
 * Represents an HTML `<input>` element in the DOM Builder.
 *
 * Extends the base DOM class to provide convenience methods for setting
 * common input attributes. The element is configured as a void element
 * (self-closing tag) by default.
 *
 * @class Input
 */
class Input extends DOM
{
    /**
     * Input constructor.
     *
     * @param string $id the attribute "id" value
     */
    public function __construct(string $id = '')
    {
        parent::__construct('', $id);
        $this->setTag('input');
        $this->setVoidElement(true);
    }

    /**
     * Set the input type attribute.
     *
     * @param string $type the input type (text, email, password, etc.)
     *
     * @return static
     */
    public function setType(string $type): static
    {
        return $this->setAttribute('type', $type);
    }

    /**
     * Set the input name attribute.
     *
     * @param mixed $name the input name
     *
     * @return static
     */
    public function setName(mixed $name): static
    {
        return $this->setAttribute('name', (string) $name);
    }

    /**
     * Set the input value attribute.
     *
     * @param string $value the input value
     *
     * @return static
     */
    public function setValue(string $value): static
    {
        return $this->setAttribute('value', $value);
    }

    /**
     * Set the placeholder attribute.
     *
     * @param string $placeholder the placeholder text
     *
     * @return static
     */
    public function setPlaceholder(string $placeholder): static
    {
        return $this->setAttribute('placeholder', $placeholder);
    }
}
