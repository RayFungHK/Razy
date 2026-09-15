<?php

/**
 * This file is part of Razy v1.1.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 *
 * @license MIT
 */

namespace Razy\Exception;

use RuntimeException;
use Throwable;

/**
 * Exception thrown when the framework wizard runner (MODULE-LIFECYCLE.md
 * L4) refuses an operation: bad/expired/spent token, a module that never
 * declared `provision => 'wizard'`, or a ledger the runner cannot reach.
 */
class SetupException extends RuntimeException
{
    public function __construct(string $message = 'Setup operation failed.', int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
