<?php
/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * Defines the Processor class for the Razy Collection system. The Processor
 * enables chained data transformations on a subset of Collection values
 * using dynamically loaded processor plugins.
 *
 * @package Razy
 * @license MIT
 */

namespace Razy\Collection;

use Razy\Collection;
use Throwable;

/**
 * Provides chainable data transformation on a subset of Collection values.
 *
 * The Processor receives a reference array of values from a Collection and
 * applies transformations via the magic `__call` method, delegating to
 * dynamically loaded processor plugins. Results can be retrieved as a new
 * Collection or a plain array.
 *
 * @class Processor
 */
class Processor
{
    /** @var Collection The parent Collection instance providing plugin loading */
    private Collection $collection;

    /** @var array Reference array of values to process */
    private array $reference;

    /**
     * Processor constructor.
     *
     * @param Collection $collection
     * @param array      $reference
     */
    public function __construct(Collection $collection, array $reference = [])
    {
        $this->reference  = $reference;
        $this->collection = $collection;
    }

    /**
     * Implement magic method __call to pass the value to the processor by the method name and its arguments.
     *
     * @param string $method
     * @param array  $arguments
     *
     * @return Processor $this
     * @throws Throwable
     */
    public function __call(string $method, array $arguments): Processor
    {
        // Apply the named processor plugin to each referenced value in-place
        foreach ($this->reference as &$data) {
            $plugin = $this->collection->loadPlugin('processor', $method);
            if ($plugin) {
                $data = call_user_func_array($plugin, array_merge([&$data], $arguments));
            }
        }

        return $this;
    }

    /**
     * Return an array with the selected values.
     *
     * @return array
     */
    public function getArray(): array
    {
        return $this->get()->array();
    }

    /**
     * Return a new Collection with the selected values.
     *
     * @return Collection
     */
    public function get(): Collection
    {
        // Copy values by value (not reference) to decouple from original data
        $values = [];
        foreach ($this->reference as $index => $value) {
            $values[$index] = $value;
        }

        return new Collection($values);
    }
}
