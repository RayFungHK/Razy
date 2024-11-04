<?php
/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Razy\FlowManager;

use Exception;
use Razy\FlowManager;

class Transmitter
{
    public function __construct(private readonly FlowManager $flowManager)
    {

    }

    public function __call(string $method, array $arguments): static
    {
        foreach ($this->flowManager->getFlows() as $flow) {
            try {
                call_user_func_array([$flow, $method], $arguments);
            }
            catch (Exception) {

            }
        }
        return $this;
    }
}