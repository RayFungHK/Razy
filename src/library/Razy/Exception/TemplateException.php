<?php

/**
 * This file is part of Razy v0.5.
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
 * Exception thrown for template parsing, block resolution, and rendering errors.
 *
 * Covers block structure errors, nesting depth limits, template file loading,
 * and invalid template argument formats.
 */
class TemplateException extends RuntimeException
{
    /**
     * @param string $message Description of the template error
     * @param int $code Error code
     * @param Throwable|null $previous The original exception
     */
    public function __construct(string $message = 'A template error occurred.', int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
