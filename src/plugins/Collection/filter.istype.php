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
 * Collection Filter Plugin: istype
 *
 * Filters Collection values by checking whether their PHP type matches
 * the specified type string (e.g., 'string', 'integer', 'array').
 * Returns true if the value matches the given type, false otherwise.
 *
 * @package Razy
 * @license MIT
 */

/**
 * Filter closure that checks if a value matches a specified PHP type.
 *
 * @param mixed  $value The value to check
 * @param string $type  The expected PHP type name (case-insensitive)
 *
 * @return bool True if the value's type matches, false otherwise
 */
return function ($value, string $type = '') {
    // Normalize the type string to lowercase for case-insensitive comparison
    $type = strtolower($type);

    // Compare the value's actual type with the expected type
    return gettype($value) === $type;
};
