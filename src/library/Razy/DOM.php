<?php

/*
 * This file is part of Razy v0.4.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Razy;

class DOM
{
    /**
     * The storage of the attribute
     * @var array
     */
    protected array $attribute = [];
    /**
     * The storage of the class name
     * @var array
     */
    protected array $className = [];
    /**
     * The storage of DOM's dataset
     * @var array
     */
    protected array $dataset = [];
    /**
     * The DOM id attribute value
     * @var string
     */
    protected string $id = '';
    /**
     * Is DOM element a void element
     * @var bool
     */
    protected bool $isVoid = false;
    /**
     * The name attribute value
     * @var string
     */
    protected string $name = '';
    /**
     * The storage of the nodes
     * @var array
     */
    protected array $nodes = [];
    /**
     * The tag name
     * @var string
     */
    protected string $tag = '';
    /**
     * The text of the DOM as a text-node
     * @var string
     */
    protected string $text = '';

    /**
     * Control constructor.
     *
     * @param string $name the attribute "name" value
     * @param string $id the attribute "id" value
     */
    public function __construct(string $name = '', string $id = '')
    {
        $name = trim($name);
        $this->name = $name;

        $id = trim($id);
        $this->id = $id;
    }

    /**
     * Call saveHTML() when trigger toString.
     *
     * @return string
     */
    final public function __toString(): string
    {
        return $this->saveHTML();
    }

    /**
     * Generate the HTML code of the Control.
     *
     * @return string The HTML code
     */
    public function saveHTML(): string
    {
        $control = '<' . $this->tag;
        if ($this->name) {
            $control .= ' name="' . $this->name . '"';
        }

        if ($this->id) {
            $control .= ' id="' . $this->id . '"';
        }

        if (count($this->attribute)) {
            foreach ($this->attribute as $attr => $value) {
                $control .= ' ' . $attr;
                if (null !== $value) {
                    $control .= '="' . $this->getHTMLValue($value) . '"';
                }
            }
        }

        if (count($this->dataset)) {
            foreach ($this->dataset as $name => $value) {
                $control .= ' data-' . $name . '="' . $this->getHTMLValue($value) . '"';
            }
        }

        if (count($this->className)) {
            $control .= ' class="' . implode(' ', $this->className) . '"';
        }

        if (!$this->isVoid) {
            $control .= '>';
            foreach ($this->nodes as $node) {
                $control .= (is_string($node)) ? $node : $node->saveHTML();
            }
            $control .= '</' . $this->tag . '>';
        } else {
            $control .= ' />';
        }

        return $control;
    }

    /**
     * The value used to convert as HTML value.
     *
     * @param mixed $value The object to convert as HTML value
     *
     * @return string The value of HTML value
     */
    final public function getHTMLValue($value): string
    {
        if (is_scalar($value)) {
            return htmlspecialchars((string)$value);
        }

        if (!is_resource($value)) {
            return htmlspecialchars(json_encode($value));
        }

        return '';
    }

    /**
     * Add a class name.
     *
     * @param array|string $className A string of the class name or an array contains the class name
     *
     * @return self Chainable
     * @throws Error
     *
     */
    final public function addClass($className): DOM
    {
        if (is_string($className)) {
            $className = trim($className);
            if ($className) {
                $this->className[$className] = true;
            }
        } elseif (is_array($className)) {
            foreach ($className as $name) {
                $this->addClass($name);
            }
        } else {
            throw new Error(gettype($className) . ' is not a valid data type.');
        }

        return $this;
    }

    /**
     * Append DOM node.
     *
     * @param DOM $dom
     *
     * @return $this
     */
    final public function append(DOM $dom): DOM
    {
        $this->nodes[] = $dom;

        return $this;
    }

    /**
     * Get the attribute.
     *
     * @param string $attribute
     *
     * @return mixed
     */
    final public function getAttribute(string $attribute)
    {
        return $this->attribute[$attribute] ?? null;
    }

    /**
     * Set the attribute.
     *
     * @param array|string $attribute The attribute name or an array contains the attribute value
     * @param mixed $value The value of the attribute
     *
     * @return self Chainable
     * @throws Error
     *
     */
    final public function setAttribute($attribute, $value = null): DOM
    {
        if (is_string($attribute)) {
            $attribute = trim($attribute);
            if ($attribute) {
                $this->attribute[$attribute] = $value;
            }
        } elseif (is_array($attribute)) {
            foreach ($attribute as $attr => $value) {
                $this->setAttribute($attr, $value);
            }
        } else {
            throw new Error(gettype($attribute) . ' is not a valid data type.');
        }

        return $this;
    }

    /**
     * Get the tag.
     *
     * @return string
     */
    public function getTag(): string
    {
        return $this->tag;
    }

    /**
     * Set the Tag.
     *
     * @param string $tag
     *
     * @return $this
     * @throws Error
     */
    public function setTag(string $tag): DOM
    {
        $tag = trim($tag);
        if (!preg_match('/^[a-z]+$/i', $tag)) {
            throw new Error('The tag name is not valid.');
        }
        $this->tag = $tag;

        return $this;
    }

    /**
     * Check if the attribute is set.
     *
     * @param string $attribute
     *
     * @return bool
     */
    final public function hasAttribute(string $attribute): bool
    {
        return isset($this->attribute[$attribute]);
    }

    /**
     * Prepend DOM node.
     *
     * @param DOM $dom
     *
     * @return $this
     */
    final public function prepend(DOM $dom): DOM
    {
        array_unshift($this->nodes, $dom);

        return $this;
    }

    /**
     * Remove an attribute.
     *
     * @param string $attribute The attribute name
     *
     * @return self Chainable
     */
    final public function removeAttribute(string $attribute): DOM
    {
        unset($this->attribute[$attribute]);

        return $this;
    }

    /**
     * Remove a class name.
     *
     * @param array|string $className A string of the class name or an array contains the class name
     *
     * @return self Chainable
     * @throws Error
     *
     */
    final public function removeClass($className): DOM
    {
        if (is_string($className)) {
            $className = trim($className);
            if ($className) {
                unset($this->className[$className]);
            }
        } elseif (is_array($className)) {
            foreach ($className as $name) {
                $this->removeClass($name);
            }
        } else {
            throw new Error(gettype($className) . ' is not a valid data type.');
        }

        return $this;
    }

    /**
     * Set the dataset value.
     *
     * @param array|string $parameter The parameter name or an array contains the dataset value
     * @param mixed $value The value of the dataset
     *
     * @return self Chainable
     * @throws Error
     *
     */
    final public function setDataset($parameter, $value = null): DOM
    {
        if (is_string($parameter)) {
            $parameter = trim($parameter);
            if ($parameter) {
                $this->dataset[$parameter] = $value;
            }
        } elseif (is_array($parameter)) {
            foreach ($parameter as $param => $value) {
                $this->setDataset($param, $value);
            }
        } else {
            throw new Error(gettype($parameter) . ' is not a valid data type.');
        }

        return $this;
    }

    /**
     * Set the attribute `name` value.
     *
     * @param mixed $value The value of the name
     *
     * @return self Chainable
     */
    final public function setName($value): DOM
    {
        $value = trim($value);
        $this->name = $value;

        return $this;
    }

    /**
     * Set the text.
     *
     * @param mixed $text
     *
     * @return self Chainable
     */
    final public function setText(string $text): DOM
    {
        if (empty($this->nodes)) {
            $this->nodes[] = $text;
        } else {
            $node = &$this->nodes[count($this->nodes) - 1];
            if (is_string($node)) {
                $node = $text;
            } else {
                $this->nodes[] = $text;
            }
        }

        return $this;
    }

    /**
     * Set the Control is void element, default.
     *
     * @param bool $enable Set true to set the Control as void element
     *
     * @return self Chainable
     */
    final public function setVoidElement(bool $enable): DOM
    {
        $this->isVoid = $enable;

        return $this;
    }
}
