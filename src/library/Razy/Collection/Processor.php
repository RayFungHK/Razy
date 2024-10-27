<?php
/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Razy\Collection;

use Razy\Collection;
use Throwable;

class Processor
{
    private Collection $collection;
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
        // Prevent convert reference into the Collection
        $values = [];
        foreach ($this->reference as $index => $value) {
            $values[$index] = $value;
        }

        return new Collection($values);
    }
}
