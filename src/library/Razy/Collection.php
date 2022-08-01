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

use ArrayObject;
use Closure;
use Razy\Collection\Processor;
use Throwable;

class Collection extends ArrayObject
{
    /**
     * @var array
     */
    private static array $pluginFolder = [];

    /**
     * @var array
     */
    private array $plugins = [];

    /**
     * Collection constructor.
     *
     * @param $data
     */
    public function __construct($data)
    {
        parent::__construct($data);
    }

    /**
     * A magic function __invoke, used to the matched elements by the filter syntax.
     *
     * @param string $filter
     *
     * @throws Throwable
     *
     * @return Processor
     */
    public function __invoke(string $filter): Processor
    {
        $filtered = [];
        $filter   = trim($filter);
        if ($filter) {
            // Extract the selectors separated by comma
            $clips = preg_split('/(?:\((\\\\.(*SKIP)|[^()]+)*\)|(?<q>[\'"])(?:\\.(*SKIP)|(?!\k<q>).)*\k<q>\.)(*SKIP)(*FAIL)|\s*,\s*/', $filter);
            foreach ($clips as $clip) {
                $clip = trim($clip);
                // Extract the paths separated by dot
                $selectors = preg_split('/(?:(?<q>[\'"])(?:\\.(*SKIP)|(?!\k<q>).)*\k<q>|\\\\.)(*SKIP)(*FAIL)|\./', $clip);
                if (!empty($selectors)) {
                    // Parse the string of the selector and merge the matched elements
                    $filtered += $this->parseSelector($selectors);
                }
            }
        }

        return new Processor($this, $filtered);
    }

    /**
     * Implement __serialize to support serialize() function.
     *
     * @return array
     */
    public function __serialize(): array
    {
        return $this->array();
    }

    /**
     * Implement __unserialize to support the unserialize() function
     * * Cannot provide type hinting of the parameter due to the ArrayObject::__unserialize($serialized) cannot be
     * override.
     *
     * @param array $data
     */
    public function __unserialize($data): void
    {
        $this->__construct($data);
    }

    /**
     * @param string $path
     */
    public static function addPluginFolder(string $path)
    {
        $path = tidy(trim($path));
        if ($path && is_dir($path)) {
            self::$pluginFolder[] = $path;
        }
    }

    /**
     * Implement offsetGet method.
     *
     * @param mixed $key
     *
     * @return mixed
     */
    public function &offsetGet($key)
    {
        $iterator = $this->getIterator();
        $result   = null;
        if (array_key_exists($key, (array) $iterator)) {
            $result = &$iterator[$key];
        }

        return $result;
    }

    /**
     * Load the plugin.
     *
     * @param string $type
     * @param string $name
     *
     * @throws Throwable
     *
     * @return null|Closure
     */
    public function loadPlugin(string $type, string $name): ?Closure
    {
        $name     = strtolower($name);
        $identify = $type . '.' . $name;

        if (!isset($this->plugins[$identify])) {
            $this->plugins[$identify] = null;
            foreach (self::$pluginFolder as $folder) {
                $pluginFile = append($folder, $identify . '.php');

                if (is_file($pluginFile)) {
                    try {
                        $closure = require $pluginFile;
                        if ($closure instanceof Closure) {
                            $this->plugins[$identify] = $closure;

                            return $this->plugins[$identify];
                        }
                    } catch (Throwable $exception) {
                        throw new Error('Missing or invalid Closure.');
                    }
                }
            }
        }

        return $this->plugins[$identify];
    }

    /**
     * Export all collected element into an array. It will also walk through the array and convert the Collection
     * object into an array too.
     *
     * @return array
     */
    public function array(): array
    {
        $recursion = function (&$aryData) use (&$recursion) {
            foreach ($aryData as &$data) {
                if ($data instanceof Collection) {
                    $recursion($data);
                    $data = $data->array();
                }
            }
        };
        $recursion($this);

        return (array) $this->getIterator();
    }

    /**
     * Filter the value by the path and the filter functions.
     *
     * @param array $selectors
     *
     * @throws Throwable
     *
     * @return array
     */
    private function parseSelector(array $selectors): array
    {
        // Put the full list into filtered array
        $filtered = ['$' => $this->getIterator()];

        // Shift a node from the selector
        while ($node = array_shift($selectors)) {
            // Match the node and extract the parts of name and filter syntax
            if (preg_match('/^(?<key>(?:\*|\w+)|(?<q>[\'"])(?:\.(*SKIP)|(?!\k<q>).)*\k<q>)((?::\w+(?:\((?:(?<value>(?P>key)|\d+(?:.\d+)?)(?:\s*,\s*(?P>value))*)?\))?)*)$/', $node, $matches)) {
                $_filtered = [];
                foreach ($filtered as $parent => &$element) {
                    // If the element is an array or iterable, walk through and filter the matched value
                    if (is_iterable($element)) {
                        // If the key name is a wildcard, put all the values into the list
                        // Define the value as a reference to prevent lost the pointer on declaring non-object value
                        if ('*' === $matches['key']) {
                            foreach ($element as $index => &$data) {
                                $_filtered[$parent . '.' . quotemeta($index)] = &$data;
                            }
                        } else {
                            // If the key is exists, put the specified value into the list
                            if (array_key_exists($matches['key'], (array) $element)) {
                                $_filtered[$parent . '.' . quotemeta($matches['key'])] = &$element[$matches['key']];
                            }
                        }
                    }
                }

                // If there is no value in the list, return an empty array
                if (empty($_filtered)) {
                    return [];
                }
                $filtered = $_filtered;

                if ($matches[3] ?? '') {
                    $this->filter($filtered, $matches[3]);
                }
            }
        }

        return $filtered;
    }

    /**
     * @param array  $filtered
     * @param string $filterSyntax
     *
     * @throws Throwable
     */
    private function filter(array &$filtered, string $filterSyntax): void
    {
        // Parse the filter syntax
        preg_match_all('/:(\w+)(?:\(((?:(?<value>(?:\*|\w+)|(?<q>[\'"])(?:\\\\.(*SKIP)|(?!\k<q>).)*\k<q>|\d+(?:.\d+)?)(?:\s*,\s*(?P>value))*)?)?\))?/', $filterSyntax, $matches, PREG_SET_ORDER);
        if (!empty($matches)) {
            foreach ($matches as $match) {
                if (empty($filtered)) {
                    $filtered = new Collection([]);

                    return;
                }

                $plugin = $this->loadPlugin('filter', $match[1]);
                if ($plugin instanceof Closure) {
                    $parameters = [];
                    $clips      = preg_split('/(?:\((\\\\.(*SKIP)|[^()]+)*\)|(?<q>[\'"])(?:\\.(*SKIP)|(?!\k<q>).)*\k<q>|\\\\.)(*SKIP)(*FAIL)|\s*,\s*/', $match[2]);
                    // Parse the string of the parameters
                    foreach ($clips as $clip) {
                        if (preg_match('/^(\w+)|(?<q>[\'"])((?:\\.(*SKIP)|(?!\k<q>).)*)\k<q>|(-?\d+(?:\.\d+)?)$/', $clip, $param)) {
                            $parameters[] = $param[4] ?? $param[3] ?? $param[1] ?? '';
                        }
                    }

                    // Walk through all the matched values and pass to the closure
                    foreach ($filtered as $index => $value) {
                        if (!call_user_func_array($plugin, array_merge([$value], $parameters))) {
                            // If the closure return false, remove the value
                            unset($filtered[$index]);
                            if (empty($filtered)) {
                                $filtered = new Collection([]);
                            }
                        }
                    }
                }
            }
        } else {
            // If the syntax is not in correct pattern, empty the matched values list
            $filtered = new Collection([]);
        }
    }
}
