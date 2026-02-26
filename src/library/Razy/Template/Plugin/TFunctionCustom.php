<?php
/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * Defines the TFunctionCustom base class for custom template function plugins.
 * Unlike TFunction, custom functions receive the raw syntax string without
 * automatic parameter parsing, allowing full control over argument handling.
 *
 * @package Razy
 * @license MIT
 */

namespace Razy\Template\Plugin;

use Razy\Controller;
use Razy\Template\Entity;

/**
 * Base class for custom template function plugins with raw syntax handling.
 *
 * Unlike TFunction, TFunctionCustom passes the raw syntax string directly
 * to `processor()` without automatic parameter parsing. This allows plugins
 * to implement entirely custom syntax parsing logic. Custom functions can
 * also optionally enclose content between open/close tags.
 *
 * @class TFunctionCustom
 */
class TFunctionCustom
{
    /** @var bool Whether this function encloses content between open/close tags */
    protected bool $encloseContent = false;

    /** @var string The registered name of this custom function plugin */
    private string $name;

	/** @var Controller|null The bound controller instance, if any */
	protected ?Controller $controller = null;

	/**
	 * Bind a Controller instance to this plugin for module context access.
	 *
	 * @param Controller $entity The controller to bind
	 * @return static Chainable
	 */
	final public function bind(Controller $entity): static
	{
		$this->controller = $entity;

		return $this;
	}

    /**
     * Process the custom function tag and return rendered output.
     *
     * Override this method in subclasses to implement custom parsing logic.
     * The raw syntax string is passed without any parameter parsing.
     *
     * @param Entity $entity The current template Entity context
     * @param string $syntax The raw syntax string from the function tag
     * @param string $wrappedText Content enclosed between open/close tags (if encloseContent is true)
     * @return string|null The rendered output string
     */
    protected function processor(Entity $entity, string $syntax = '', string $wrappedText = ''): ?string {
        return '';
    }

    /**
     * Start parse the function tag.
     *
     * @param Entity $entity The Entity instance
     * @param string $syntax
     * @param string $wrappedText The wrapped content if the plugin is an enclosure tag
     *
     * @return string|null
     */
    final public function parse(Entity $entity, string $syntax = '', string $wrappedText = ''): ?string
    {
        return $this->processor($entity, $syntax, $wrappedText);
    }

    /**
     * Check whether this function encloses content between open/close tags.
     *
     * @return bool True if this is an enclosure-type function
     */
    final public function isEncloseContent(): bool
    {
        return $this->encloseContent;
    }

    /**
     * Get the registered name of this custom function plugin.
     *
     * @return string The plugin name
     */
    final public function getName(): string
    {
        return $this->name;
    }

    /**
     * Set the registered name of this custom function plugin.
     *
     * @param string $name The plugin name
     * @return TFunctionCustom Chainable
     */
    final public function setName(string $name): TFunctionCustom
    {
        $this->name = $name;

        return $this;
    }
}