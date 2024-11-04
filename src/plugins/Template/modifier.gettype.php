<?php

/*
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

use Razy\Template\Plugin\TModifier;

return function (...$arguments) {
    return new class(...$arguments) extends TModifier {
        protected function process(mixed $value, string ...$args): string
        {
            return gettype($value);
        }
    };
};
