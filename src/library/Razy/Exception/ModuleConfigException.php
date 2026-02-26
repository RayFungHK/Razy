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
 * Exception thrown when a module configuration is invalid.
 *
 * Covers package.php schema validation, invalid module code format,
 * missing required configuration fields, and dist format errors.
 */
class ModuleConfigException extends ModuleException
{
    /**
     * @param string $message Description of the configuration error
     * @param int $code Error code
     * @param Throwable|null $previous The original exception
     */
    public function __construct(string $message = 'Invalid module configuration.', int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
