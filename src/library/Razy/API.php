<?php
/**
 * This file is part of Razy v0.4.
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
     * @var Distributor
     */
    private Distributor $distributor;

    /**
     * API constructor.
     *
     * @param Distributor $distributor The Distributor instance
     */
    public function __construct(Distributor $distributor)
    {
        $this->distributor = $distributor;
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
        $module = $this->distributor->requestModule($moduleCode);
        if ($module) {
            return new Emitter($module);
        }
        return new Emitter();
    }
}
