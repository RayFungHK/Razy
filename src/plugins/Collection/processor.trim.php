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
 * Collection Processor Plugin: trim
 *
 * Trims leading and trailing whitespace from string values in a Collection.
 * Non-string values are returned unchanged.
 *
 * @package Razy
 * @license MIT
 */

/**
 * Processor closure that trims whitespace from string values.
 *
 * @param mixed $value The value to process
 *
 * @return mixed The trimmed string, or the original value if not a string
 */
return function ($value) {
    // Only trim if the value is a string; leave other types untouched
    if (is_string($value)) {
        return trim($value);
    }

    return $value;
};
