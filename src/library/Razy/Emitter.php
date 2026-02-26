<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 *
 * @license MIT
 */

namespace Razy;

use Throwable;

/**
 * Class Emitter.
 *
 * Provides a proxy for cross-module API communication. When a module requests
 * access to another module's API, it receives an Emitter instance that delegates
 * method calls to the target module's registered API commands via __call magic.
 *
 * @class Emitter
 */
class Emitter
{
    /**
     * Emitter constructor.
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
     *
     * @return mixed|null
     *
     * @throws Throwable
     */
    public function __call(string $method, array $arguments)
    {
        // Delegate the call to the target module's API; return null if no target module is set
        return ($this->module) ? $this->module->execute($this->requestedBy->getModuleInfo(), $method, $arguments) : null;
    }
}
