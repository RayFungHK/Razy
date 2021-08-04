<?php

/*
 * This file is part of Razy v0.4.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Razy\Template;

use Closure;
use Razy\Error;
use Razy\FileReader;
use Razy\Template;
use Throwable;

/**
 * Template Source is an object contains the file structure and its parameters.
 */
class Source
{
	/**
	 * The root Entity object.
	 *
	 * @var Entity
	 */
	private $root = [];

	/**
	 * An array contains the Source parameters.
	 *
	 * @var array
	 */
	private array $parameters = [];

	/**
	 * The Template object.
	 *
	 * @var Template
	 */
	private Template $template;

	/**
	 * The template source located directory.
	 *
	 * @var string
	 */
	private string $fileDirectory = '';

	/**
	 * Template Source constructor.
	 *
	 * @param string   $tplPath  The path of template file
	 * @param Template $template The Template object
	 *
	 * @throws Throwable
	 */
	public function __construct(string $tplPath, Template $template)
	{
		if (!is_file($tplPath)) {
			throw new Error('Template file ' . $tplPath . ' is not exists.');
		}

		$this->fileDirectory = dirname(realpath($tplPath));
		$this->template      = $template;

		$this->root = new Entity(new Block($this, '_ROOT', new FileReader($tplPath)));
	}

	/**
	 * Link to a specified template engine entity.
	 *
	 * @param \Razy\Template $template
	 *
	 * @return $this
	 */
	public function link(Template $template): Source
	{
		$this->template = $template;

		return $this;
	}

	/**
	 * Assign the source level parameter value.
	 *
	 * @param mixed $parameter The parameter name or an array of parameters
	 * @param mixed $value     The parameter value
	 *
	 * @throws Throwable
	 *
	 * @return self Chainable
	 */
	public function assign($parameter, $value = null): Source
	{
		if (is_array($parameter)) {
			foreach ($parameter as $index => $value) {
				$this->assign($index, $value);
			}
		} elseif (is_string($parameter)) {
			if (is_object($value) && ($value instanceof Closure)) {
				// If the value is closure, pass the current value to closure
				$this->parameters[$parameter] = $value($this->parameters[$parameter] ?? null);
			} else {
				$this->parameters[$parameter] = $value;
			}
		} else {
			throw new Error('Invalid parameter name');
		}

		return $this;
	}

	/**
	 * @param string $parameter
	 * @param null   $value
	 *
	 * @throws \Throwable
	 *
	 * @return $this
	 */
	public function bind(string $parameter, &$value): Source
	{
		$this->parameters[$parameter] = &$value;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getID(): string
	{
		return spl_object_hash($this);
	}

	/**
	 * Return the parameter value.
	 *
	 * @param string $parameter The parameter name
	 * @param bool   $recursion
	 *
	 * @return mixed The parameter value
	 */
	public function getValue(string $parameter, bool $recursion = false)
	{
		if ($recursion) {
			if (!$this->parameterAssigned($parameter)) {
				return $this->template->getValue($parameter);
			}
		}

		return $this->parameters[$parameter] ?? null;
	}

	/**
	 * Determine the parameter has been assigned.
	 *
	 * @param string $parameter The parameter name
	 *
	 * @return bool Return true if the parameter is exists
	 */
	public function parameterAssigned(string $parameter): bool
	{
		return array_key_exists($parameter, $this->parameters);
	}

	/**
	 * Add current template source into queue list.
	 *
	 * @param string $name The section name
	 *
	 * @return Source
	 */
	public function queue(string $name = ''): Source
	{
		$this->template->addQueue($this, $name);

		return $this;
	}

	/**
	 * Get the root entity.
	 *
	 * @return Entity The root entity object
	 */
	public function getRootBlock()
	{
		return $this->root;
	}

	/**
	 * Get the template file located directory.
	 *
	 * @return string The directory of the template file
	 */
	public function getFileDirectory(): string
	{
		return $this->fileDirectory;
	}

	/**
	 * Return the entity content.
	 *
	 * @throws \Throwable
	 *
	 * @return string The entity content
	 */
	public function output(): string
	{
		return $this->root->process();
	}

	/**
	 * @param string $type
	 * @param string $name
	 *
	 * @throws Throwable
	 *
	 * @return null|Closure
	 */
	public function loadPlugin(string $type, string $name): ?Plugin
	{
		return $this->template->loadPlugin($type, $name);
	}
}
