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
use Razy\Controller;
use Razy\Error;
use Razy\FileReader;
use Razy\ModuleInfo;
use Razy\Template;
use Razy\Template\Plugin\TFunctionCustom;
use Throwable;
use Razy\Template\Plugin\TFunction;
use Razy\Template\Plugin\TModifier;

/**
 * Template Source is an object contains the file structure and its parameters.
 */
class Source
{
	/**
	 * The template source located directory.
	 *
	 * @var string
	 */
	private string $fileDirectory = '';
	/**
	 * An array contains the Source parameters.
	 *
	 * @var array
	 */
	private array $parameters = [];
	/**
	 * @var Block|null
	 */
	private ?Block $rootBlock = null;
	/**
	 * The root Entity object.
	 *
	 * @var ?Entity
	 */
	private ?Entity $rootEntity = null;
	/**
	 * The Template object.
	 *
	 * @var Template
	 */
	private Template $template;

	/**
	 * @var ModuleInfo|null
	 */
	private ?ModuleInfo $module = null;

	/**
	 * Template Source constructor.
	 *
	 * @param string $tplPath The path of template file
	 * @param Template $template The Template object
	 *
	 * @throws Throwable
	 */
	public function __construct(string $tplPath, Template $template, ?ModuleInfo $module = null)
	{
		if (!is_file($tplPath)) {
			throw new Error('Template file ' . $tplPath . ' is not exists.');
		}

		$this->fileDirectory = dirname(realpath($tplPath));
		$this->template = $template;
		$this->module = $module;

		$this->rootEntity = new Entity(($this->rootBlock = new Block($this, '_ROOT', new FileReader($tplPath))));
	}

	/**
	 * Get the module which was loaded the template file.
	 *
	 * @return ModuleInfo|null
	 */
	public function getModule(): ?ModuleInfo
	{
		return $this->module;
	}

	/**
	 * Assign the source level parameter value.
	 *
	 * @param mixed $parameter The parameter name or an array of parameters
	 * @param mixed|null $value The parameter value
	 *
	 * @return self Chainable
	 * @throws Throwable
	 *
	 */
	public function assign(mixed $parameter, mixed $value = null): Source
	{
		if (is_array($parameter)) {
			foreach ($parameter as $index => $value) {
				$this->assign($index, $value);
			}
		} elseif (is_string($parameter)) {
			if ($value instanceof Closure) {
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
	 * Bind reference parameter
	 *
	 * @param string $parameter
	 * @param null $value
	 *
	 * @return $this
	 * @throws Throwable
	 */
	public function bind(string $parameter, null &$value): Source
	{
		$this->parameters[$parameter] = &$value;

		return $this;
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
	 * Get the instance hash ID
	 *
	 * @return string
	 */
	public function getID(): string
	{
		return spl_object_hash($this);
	}

	/**
	 * Get the root entity.
	 *
	 * @return Entity|null The root entity object
	 */
	public function getRoot(): ?Entity
	{
		return $this->rootEntity;
	}

	/**
	 * Get the root entity.
	 *
	 * @return Block|null The root block object
	 */
	public function getRootBlock(): ?Block
	{
		return $this->rootBlock;
	}

	/**
	 * Get the template block
	 *
	 * @param string $name
	 *
	 * @return Block|null
	 */
	public function getTemplate(string $name): ?Block
	{
		return $this->template->getTemplate($name);
	}

	/**
	 * Return the parameter value.
	 *
	 * @param string $parameter The parameter name
	 * @param bool $recursion
	 *
	 * @return mixed The parameter value
	 */
	public function getValue(string $parameter, bool $recursion = false): mixed
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
	 * @return bool Return true if the parameter is existing
	 */
	public function parameterAssigned(string $parameter): bool
	{
		return array_key_exists($parameter, $this->parameters);
	}

	/**
	 * Link to a specified template engine entity.
	 *
	 * @param Template $template
	 *
	 * @return $this
	 */
	public function link(Template $template): Source
	{
		$this->template = $template;

		return $this;
	}

	/**
	 * Load the plugin from the plugin function by given name
	 *
	 * @param string $type
	 * @param string $name
	 *
	 * @return TModifier|TFunction|TFunctionCustom|null
	 * @throws Error
	 * @throws Throwable
	 */
	public function loadPlugin(string $type, string $name): null|TModifier|TFunction|TFunctionCustom
	{
		return $this->template->loadPlugin($type, $name);
	}

	/**
	 * Return the entity content.
	 *
	 * @return string The entity content
	 * @throws Throwable
	 */
	public function output(): string
	{
		return $this->rootEntity->process();
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
}
