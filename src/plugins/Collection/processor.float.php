<?php

/*
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

/**
 * Collection Processor Plugin: float
 *
 * Casts scalar values to floating-point numbers in a Collection.
 * Non-scalar values (arrays, objects, etc.) are returned as 0.
 *
 * @package Razy
 * @license MIT
 */

/**
 * Processor closure that casts values to float.
 *
 * @param mixed $value The value to process
 *
 * @return float|int The float representation, or 0 for non-scalar values
 */
return function ($value) {
    // Cast scalar values (string, int, float, bool) to float
    if (is_scalar($value)) {
        return (float) $value;
    }

    // Return 0 for non-scalar types
    return 0;
};
