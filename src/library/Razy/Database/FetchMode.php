<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * @package Razy
 *
 * @license MIT
 */

namespace Razy\Database;

/**
 * Enum FetchMode.
 *
 * Defines the available fetch modes for Query::fetchAll().
 * Replaces magic string arguments ('group', 'keypair', '') with type-safe enum values.
 */
enum FetchMode: string
{
    /** Standard associative fetch (PDO::FETCH_ASSOC) — default mode */
    case Standard = 'standard';

    /** Group mode: first column becomes key, values are arrays of matching rows */
    case Group = 'group';

    /** Key-pair mode: first column is key, second column is value */
    case KeyPair = 'keypair';
}
