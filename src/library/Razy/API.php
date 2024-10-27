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

class API
{
    /**
     * API constructor.
     *
     * @param Module $requestedBy
     * @param Distributor $distributor
     */
    public function __construct(private readonly Distributor $distributor, private readonly Module $requestedBy)
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
        $module = $this->distributor->getLoadedAPIModule($moduleCode);
        if ($module) {
            return new Emitter($this->requestedBy, $module);
        }
        return new Emitter($this->requestedBy);
    }
}
