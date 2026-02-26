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
 * @license MIT
 */

namespace Razy\Exception;

use Throwable;

/**
 * Exception thrown when a module fails to load.
 *
 * Covers prerequisite version conflicts, controller class validation,
 * missing controller files, and closure loading failures.
 */
class ModuleLoadException extends ModuleException
{
    /**
     * @param string $message Description of the load error
     * @param int $code Error code
     * @param Throwable|null $previous The original exception
     */
    public function __construct(string $message = 'Module failed to load.', int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
