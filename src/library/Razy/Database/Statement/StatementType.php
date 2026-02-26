<?php

/**
 * This file is part of Razy v0.5.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 * PHP 8.1 backed enum replacing magic type strings in Statement.
 *
 *
 * @license MIT
 */

namespace Razy\Database\Statement;

/**
 * Represents the type of SQL statement being built.
 */
enum StatementType: string
{
    case Select = 'select';
    case Insert = 'insert';
    case Update = 'update';
    case Delete = 'delete';
    case Raw = 'sql';
    case Replace = 'replace';
}
