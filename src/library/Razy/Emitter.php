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

use Throwable;

class Emitter
{
    /**
     * Emitter constructor
     *
     * @param Module $requestedBy The request module
     * @param Module|null $module The API module
     */
    public function __construct(private readonly Module $requestedBy, private readonly ?Module $module = null)
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
        return ($this->module) ? $this->module->execute($this->requestedBy->getModuleInfo(), $method, $arguments) : null;
    }
}