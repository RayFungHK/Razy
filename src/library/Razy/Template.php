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

use Closure;
use Razy\Template\Plugin;
use Razy\Template\Source;
use Throwable;

/**
 * Fast and easy-to-use template engine. You can control every block from template file and assign parameter for
 * post-process output.
 */
class Template
{
	/**
	 * An array contains the source object.
	 *
	 * @var Source[]
	 */
	private array $sources = [];

	/**
	 * An array contains the manager level parameter.
	 *
	 * @var array
	 */
	private array $parameters = [];

	/**
	 * @var Source[]
	 */
	private array $queue = [];

	/**
	 * @var ?\Closure[]
	 */
	private array $plugins = [];

	/**
	 * @var string[]
	 */
	private static array $pluginFolder = [];

	/**
	 * Template constructor.
	 *
	 * @param string $folder
	 */
	public function __construct(string $folder = '')
	{
		$this->addPluginFolder($folder);
	}

	/**
	 * @param string $folder
	 */
	public static function addPluginFolder(string $folder)
	{
		// Setup plugin folder
		$folder = tidy(trim($folder));
		if ($folder && is_dir($folder)) {
			self::$pluginFolder[] = $folder;
		}
	}

	/**
	 * Assign the manager level parameter value.
	 *
	 * @param mixed $parameter The parameter name or an array of parameters
	 * @param mixed $value     The parameter value
	 *
	 * @throws Throwable
	 *
	 * @return self Chainable
	 */
	public function assign($parameter, $value = null): Template
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
			throw new Error('Invalid parameter name.');
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
	public function bind(string $parameter, &$value): Template
	{
		$this->parameters[$parameter] = $value;

		return $this;
	}

	/**
	 * Return the parameter value.
	 *
	 * @param string $parameter The parameter name
	 *
	 * @return mixed The parameter value
	 */
	public function getValue(string $parameter)
	{
		return $this->parameters[$parameter] ?? null;
	}

	/**
	 * Load the template file and return as Source object.
	 *
	 * @param string $path The file path
	 *
	 * @throws Throwable
	 *
	 * @return Source The Source object
	 */
	public function load(string $path): Source
	{
		$source                          = new Source($path, $this);
		$this->sources[$source->getID()] = $source;

		return $source;
	}

	/**
	 * Load the template file without any plugin folder setup and return as Source object.
	 *
	 * @param string $path
	 *
	 * @throws \Throwable
	 *
	 * @return \Razy\Template\Source
	 */
	public static function LoadFile(string $path): Source
	{
		return (new Template())->load($path);
	}

	/**
	 * Return the entity content in queue list by given section name.
	 *
	 * @param array $sections An array contains section name
	 *
	 * @throws \Throwable
	 *
	 * @return string
	 */
	public function outputQueued(array $sections): string
	{
		$content = '';
		foreach ($sections as $section) {
			if (isset($this->queue[$section])) {
				$content .= $this->queue[$section]->output();
			}
		}
		$this->queue = [];

		return $content;
	}

	/**
	 * Insert other Source entity into template engine.
	 *
	 * @param \Razy\Template\Source $source
	 *
	 * @return $this
	 */
	public function insert(Source $source): Template
	{
		$this->sources[$source->getID()] = $source;
		$source->link($this);

		return $this;
	}

	/**
	 * Get the plugin closure from the plugin pool.
	 *
	 * @param string $type The type of the plugin
	 * @param string $name The plugin name
	 *
	 * @throws Throwable
	 *
	 * @return null|Plugin The plugin
	 */
	public function loadPlugin(string $type, string $name): ?Plugin
	{
		$name     = strtolower($name);
		$identify = $type . '.' . $name;

		if (!isset($this->plugins[$identify])) {
			foreach (self::$pluginFolder as $folder) {
				$pluginFile = append($folder, $identify . '.php');
				if (is_file($pluginFile)) {
					try {
						/** @noinspection PhpIncludeInspection */
						$setting = require $pluginFile;
						if (is_array($setting)) {
							$this->plugins[$identify] = new Plugin($setting, $type, $name);

							return $this->plugins[$identify];
						}
					} catch (Throwable $exception) {
						throw new Error('Missing or invalid Closure');
					}
				}
			}
			if (!isset($this->plugins[$identify])) {
				$this->plugins[$identify] = null;
			}
		}

		return $this->plugins[$identify];
	}

	/**
	 * Add a source to queue list.
	 *
	 * @param Source $source The Source object
	 * @param string $name   The queue name
	 *
	 * @return Template
	 */
	public function addQueue(Source $source, string $name): Template
	{
		$exists = $this->sources[$source->getID()] ?? null;
		if (!$exists || $exists !== $source) {
			// If the Source entity is not under the Template engine, skip adding queue list.
			return $this;
		}
		$name = trim($name);
		if (!$name) {
			$name = sprintf('%04x%04x', mt_rand(0, 0xffff), mt_rand(0, 0xffff));
		}
		$this->queue[$name] = $source;

		return $this;
	}
}
