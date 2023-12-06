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
use InvalidArgumentException;
use Razy\Template\Block;
use Razy\Template\Plugin\TFunctionCustom;
use Razy\Template\Plugin\TModifier;
use Razy\Template\Plugin\TFunction;
use Razy\Template\Source;
use ReflectionClass;
use Throwable;

/**
 * Fast and easy-to-use template engine. You can control every block from template file and assign parameter for
 * post-process output.
 */
class Template
{
    /**
     * The storage of the plugin folder
     * @var string[]
     */
    private static array $pluginFolder = [];
    /**
     * An array contains the manager level parameter.
     */
    private array $parameters = [];
    /**
     * The storage of the plugin's closure
     * @var ?Closure[]
     */
    private array $plugins = [];
    /**
     * The storage of the source
     * @var Source[]
     */
    private array $queue = [];
    /**
     * An array contains the source object.
     *
     * @var Source[]
     */
    private array $sources = [];
    /**
     * The storage of the template content
     * @var array
     */
    private array $templates = [];

    /**
     * Template constructor.
     *
     */
    public function __construct(string $folder = '')
    {
        $this->addPluginFolder($folder);
    }

    /**
     * Add a plugin folder which the plugin is load
     *
     * @param string $folder
     * @param mixed $entity
     * @return void
     */
    public static function addPluginFolder(string $folder, mixed $entity = null): void
    {
        // Setup plugin folder
        $folder = tidy(trim($folder));
        if ($folder && is_dir($folder)) {
            self::$pluginFolder[$folder] = $entity;
        }
    }

    /**
     * Load the template file without any plugin folder setup and return as Source object.
     *
     * @param string $path
     * @return Source
     * @throws Throwable
     */
    public static function LoadFile(string $path): Source
    {
        return (new Template())->load($path);
    }

    /**
     * Load the template file and return as Source object.
     *
     * @param string $path The file path
     *
     * @return Source The Source object
     * @throws Throwable
     */
    public function load(string $path): Source
    {
        $source                          = new Source($path, $this);
        $this->sources[$source->getID()] = $source;

        return $source;
    }

    /**
     * Add a source to queue list.
     *
     * @param Source $source
     * @param string $name
     * @return $this
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

    /**
     * Assign the manager level parameter value.
     *
     * @param mixed $parameter The parameter name or an array of parameters
     * @param mixed|null $value The parameter value
     *
     * @return self Chainable
     * @throws Throwable
     */
    public function assign(mixed $parameter, mixed $value = null): Template
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
            throw new Error('Invalid parameter name.');
        }

        return $this;
    }

    /**
     * Bind the reference variable
     *
     * @param string $parameter
     * @param $value
     * @return $this
     */
    public function bind(string $parameter, &$value): Template
    {
        $this->parameters[$parameter] = $value;

        return $this;
    }

    /**
     * Get the template content by given name
     *
     * @param string $name
     * @return Block|null
     */
    public function getTemplate(string $name): ?Block
    {
        return $this->templates[$name] ?? null;
    }

    /**
     * Return the parameter value.
     *
     * @param string $parameter The parameter name
     *
     * @return mixed The parameter value
     */
    public function getValue(string $parameter): mixed
    {
        return $this->parameters[$parameter] ?? null;
    }

    /**
     * Insert other Source entity into template engine.
     *
     * @param Source $source
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
     * @return TModifier|TFunction|TFunctionCustom|null The plugin entity
     * @throws Error
     */
    public function loadPlugin(string $type, string $name): TModifier|TFunction|TFunctionCustom|null
    {
        $name     = strtolower($name);
        $identify = $type . '.' . $name;

        if (!isset($this->plugins[$identify])) {
            foreach (self::$pluginFolder as $folder => $entity) {
                $pluginFile = append($folder, $identify . '.php');
                if (is_file($pluginFile)) {
                    try {
                        $plugin = require $pluginFile;
                        $reflection = new ReflectionClass($plugin);
                        if ($reflection->isAnonymous()) {
                            $parent = $reflection->getParentClass();
                            if (('function' === $type && ($parent->getName() === 'Razy\Template\Plugin\TFunction' || $parent->getName() === 'Razy\Template\Plugin\TFunctionCustom')) || ('modifier' === $type && $plugin instanceof TModifier)) {
                                $plugin = (new $plugin())->setName($name);
                                if ($entity) {
                                    $plugin->bind($entity);
                                }
                                return ($this->plugins[$identify] = $plugin);
                            }
                        }
                        throw new Error('Missing or invalid plugin entity');
                    } catch (Throwable $exception) {
                        throw new Error('Failed to load the plugin');
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
     * Load the template file as a global template block
     *
     * @param $name
     * @param string|null $path
     * @return $this
     * @throws Throwable
     */
    public function loadTemplate($name, ?string $path = null): Template
    {
        if (is_array($name)) {
            foreach ($name as $filepath => $tplName) {
                $this->loadTemplate($filepath, $tplName);
            }
            return $this;
        } elseif (is_string($name)) {
            $name = trim($name);
            if (!preg_match('/^\w+$/', $name)) {
                throw new Error('Invalid template name format.');
            }

            $this->templates[$name] = (new Source($path, $this))->getRootBlock();
            return $this;
        }

        throw new InvalidArgumentException('Invalid argument type of path, only string or array is accepted.');
    }

    /**
     * Return the entity content in queue list by given section name.
     *
     * @param array $sections An array contains section name
     * @throws Throwable
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
}
