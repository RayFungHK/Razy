<?php

namespace Razy;

use Throwable;

class Emitter
{
    /**
     * Emitter constructor
     *
     * @param Module|null $module
     */
    public function __construct(private readonly ?Module $module = null)
    {

    }

    /**
     * Magic Methods __call, pass the method name to Module's and execute the API. If no API command was found it will return null.
     *
     * @param string $method
     * @param array $arguments
     * @return mixed|null
     * @throws Throwable
     */
    public function __call(string $method, array $arguments)
    {
        return ($this->module) ? $this->module->execute($this->module->getModuleInfo(), $method, $arguments) : null;
    }
}