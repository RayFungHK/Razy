<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Razy;

use ArrayObject;
use Closure;
use InvalidArgumentException;
use Razy\Collection\Processor;
use Throwable;

/**
 * Collection extends ArrayObject with advanced filtering, selection, and plugin capabilities.
 *
 * Supports a dot-notation filter syntax (invoked via __invoke) to query nested elements,
 * with chainable filter functions loaded as plugins. Provides serialization support
 * and recursive array conversion.
 *
 * @class Collection
 *
 * @package Razy
 *
 * @license MIT
 */
class Collection extends ArrayObject
{
    use PluginTrait;

    /** @var array<string, Closure> Cached plugin closures keyed by "type.name" identifier */
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
     * @return Processor
     *
     * @throws Throwable
     */
    public function __invoke(string $filter): Processor
    {
        $filtered = [];
        $filter = \trim($filter);
        if ($filter) {
            // Split filter string by commas, respecting quoted strings and parenthesized groups
            $clips = \preg_split('/(?:\((\\\\.(\*SKIP)|[^()]+)*\)|(?<q>[\'"])(?:\\.(\*SKIP)|(?!\k<q>).)\*\k<q>\.)(*SKIP)(*FAIL)|\s*,\s*/', $filter);
            foreach ($clips as $clip) {
                $clip = \trim($clip);
                // Split each selector by dots, respecting quoted segments and escaped chars
                $selectors = \preg_split('/(?:(?<q>[\'"])(?:\\.(*SKIP)|(?!\k<q>).)*\k<q>|\\\\.)(*SKIP)(*FAIL)|\./', $clip);
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
    public function __unserialize(array $data): void
    {
        $this->__construct($data);
    }

    /**
     * Load the plugin.
     *
     * @param string $type
     * @param string $name
     *
     * @return Closure|null
     *
     * @throws Throwable
     */
    public function loadPlugin(string $type, string $name): ?Closure
    {
        $name = \strtolower($name);
        $identify = $type . '.' . $name;

        if (!isset($this->plugins[$identify])) {
            if ($plugin = self::GetPlugin($identify)) {
                if ($plugin['entity'] instanceof Closure) {
                    try {
                        $this->plugins[$identify] = $plugin['entity'];

                        return $this->plugins[$identify];
                    } catch (Throwable) {
                        throw new InvalidArgumentException('Missing or invalid Closure.');
                    }
                }
            }
        }

        return $this->plugins[$identify];
    }

    /**
     * Export all elements into a plain array, recursively converting nested Collection objects.
     *
     * @return array
     */
    public function array(): array
    {
        // Recursively walk the data tree and convert any Collection instances to arrays
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
     * Get a value by key from the underlying iterator, returned by reference.
     *
     * Returns null by reference if the key does not exist.
     *
     * @param mixed $key The array key to retrieve
     *
     * @return mixed The value at the given key, or null
     */
    public function &offsetGet(mixed $key): mixed
    {
        $iterator = $this->getIterator();
        $result = null;
        if (\array_key_exists($key, (array) $iterator)) {
            $result = &$iterator[$key];
        }

        return $result;
    }

    /**
     * Parse a dot-separated selector path and resolve matching elements from the collection.
     *
     * Each segment can be a key name, wildcard '*', or quoted string, optionally followed
     * by filter function chains (e.g., :type('string'):gt(5)).
     *
     * @param array $selectors Array of selector path segments
     *
     * @return array Matched elements keyed by their resolved path
     *
     * @throws Throwable
     */
    private function parseSelector(array $selectors): array
    {
        // Initialize with the root iterator as the starting dataset
        $filtered = ['$' => $this->getIterator()];

        // Process each selector node sequentially, narrowing the result set
        while ($node = \array_shift($selectors)) {
            // Regex matches: key (word, wildcard, or quoted string) + optional filter chain
            if (\preg_match('/^(?<key>(?:\*|\w+)|(?<q>[\'"])(?:\.(*SKIP)|(?!\k<q>).)*\k<q>)((?::\w+(?:\((?:(?<value>(?P>key)|\d+(?:.\d+)?)(?:\s*,\s*(?P>value))*)?\))?)*)$/', $node, $matches)) {
                $_filtered = [];
                foreach ($filtered as $parent => &$element) {
                    // Only descend into iterable elements (arrays, ArrayObjects, etc.)
                    if (\is_iterable($element)) {
                        // Wildcard '*': include all child elements in the result set
                        if ('*' === $matches['key']) {
                            // Use reference to preserve pointer for non-object values
                            foreach ($element as $index => &$data) {
                                $_filtered[$parent . '.' . \quotemeta($index)] = &$data;
                            }
                        } else {
                            // Named key: include only the element matching this key
                            if (\array_key_exists($matches['key'], (array) $element)) {
                                $_filtered[$parent . '.' . \quotemeta($matches['key'])] = &$element[$matches['key']];
                            }
                        }
                    }
                }

                // No matches found at this depth; short-circuit with empty result
                if (empty($_filtered)) {
                    return [];
                }
                $filtered = $_filtered;

                // Apply chained filter functions if present in the selector
                if ($matches[3] ?? '') {
                    $this->filter($filtered, $matches[3]);
                }
            }
        }

        return $filtered;
    }

    /**
     * Apply filter function chains to the filtered element set.
     *
     * Parses filter syntax like ":type('string'):gt(5)" and invokes the corresponding
     * plugin closures. Elements for which the filter returns false are removed.
     *
     * @param array $filtered Reference to the filtered elements array
     * @param string $filterSyntax The raw filter chain string (e.g., ":name(args)")
     *
     * @throws Throwable
     */
    private function filter(array &$filtered, string $filterSyntax): void
    {
        // Extract all filter function invocations from the syntax string
        \preg_match_all('/:(\w+)(?:\(((?:(?<value>(?:\*|\w+)|(?<q>[\'"])(?:\\\\.(*SKIP)|(?!\k<q>).)*\k<q>|\d+(?:.\d+)?)(?:\s*,\s*(?P>value))*)?)?\))?/', $filterSyntax, $matches, PREG_SET_ORDER);
        if (!empty($matches)) {
            foreach ($matches as $match) {
                // If all elements already filtered out, return empty Collection
                if (empty($filtered)) {
                    $filtered = new self([]);

                    return;
                }

                // Load the named filter plugin (e.g., 'type', 'gt', 'not_empty')
                $plugin = $this->loadPlugin('filter', $match[1]);
                if ($plugin instanceof Closure) {
                    $parameters = [];
                    // Split filter arguments by commas, respecting quoted strings and nested parens
                    $clips = \preg_split('/(?:\((\\\\.(*SKIP)|[^()]+)*\)|(?<q>[\'"])(?:\\.(*SKIP)|(?!\k<q>).)*\k<q>|\\\\.)(*SKIP)(*FAIL)|\s*,\s*/', $match[2]);
                    // Parse the string of the parameters
                    foreach ($clips as $clip) {
                        // Extract each parameter value: word, quoted string, or numeric literal
                        if (\preg_match('/^(\w+)|(?<q>[\'"])((?:\\.(*SKIP)|(?!\k<q>).)*)\k<q>|(-?\d+(?:\.\d+)?)$/', $clip, $param)) {
                            $parameters[] = $param[4] ?? $param[3] ?? $param[1] ?? '';
                        }
                    }

                    // Apply filter to each element; remove those that don't pass
                    foreach ($filtered as $index => $value) {
                        if (!\call_user_func_array($plugin, \array_merge([$value], $parameters))) {
                            // Filter returned false: exclude this element
                            unset($filtered[$index]);
                            if (empty($filtered)) {
                                $filtered = new self([]);
                            }
                        }
                    }
                }
            }
        } else {
            // Invalid filter syntax pattern: reset to empty Collection
            $filtered = new self([]);
        }
    }
}
