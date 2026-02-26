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

use Razy\Contract\DistributorInterface;

/**
 * API provides a gateway for modules to access other modules' API commands.
 *
 * Controllers use this class to request an Emitter for a target module,
 * which can then be used to invoke that module's registered API commands.
 *
 * @class API
 *
 * @package Razy
 *
 * @license MIT
 */
class API
{
    /**
     * API constructor.
     *
     * @param Module $requestedBy
     * @param DistributorInterface $distributor
     */
    public function __construct(private readonly DistributorInterface $distributor, private readonly Module $requestedBy)
    {
    }

    /**
     * Get the emitter by given module code.
     *
     * @param string $moduleCode
     *
     * @return Emitter|null
     */
    public function request(string $moduleCode): ?Emitter
    {
        // Look up the target module by code in the distributor's loaded API modules
        $module = $this->distributor->getRegistry()->getLoadedAPIModule($moduleCode);
        if ($module) {
            // Return an emitter bound to both the requesting and target modules
            return new Emitter($this->requestedBy, $module);
        }
        // Return an unbound emitter (calls will fail gracefully) if module not found
        return new Emitter($this->requestedBy);
    }
}
