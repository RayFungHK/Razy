<?php
/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Razy\DOM;

use Razy\DOM;

class Input extends DOM
{
    /**
     * Input constructor.
     *
     * @param string $id the attribute "id" value
     */
    public function __construct(string $id = '')
    {
        parent::__construct('input', $id);
    }
}
